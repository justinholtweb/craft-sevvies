<?php

namespace justinholtweb\sevvies\services;

use Craft;
use craft\commerce\elements\Order;
use justinholtweb\sevvies\helpers\Vat;
use justinholtweb\sevvies\models\Settings;
use justinholtweb\sevvies\models\TaxDecision;
use justinholtweb\sevvies\Plugin;
use yii\base\Component;

/**
 * Decides which German VAT rule an order falls under.
 *
 * This is the part of the plugin that is not a REST client. sevDesk will
 * happily book an intra-community supply as a domestic sale if you tell it to,
 * and the merchant finds out at the Umsatzsteuervoranmeldung. So the rule is
 * derived from the order, the reasoning is recorded, and a rate that cannot
 * legally accompany the rule is a refusal, not a warning.
 */
class Tax extends Component
{
    /** Umsatzsteuerpflichtige Umsätze — the ordinary domestic sale. */
    public const RULE_DOMESTIC = '1';
    /** Ausfuhren — export to a third country. */
    public const RULE_EXPORT = '2';
    /** Innergemeinschaftliche Lieferungen — intra-community supply, reverse charge. */
    public const RULE_INTRA_EU = '3';
    /** Steuerfreie Umsätze §4 UStG. */
    public const RULE_TAX_FREE = '4';
    /** Steuer nicht erhoben nach §19 UStG — Kleinunternehmer. */
    public const RULE_SMALL_BUSINESS = '11';
    /** Nicht im Inland steuerbare Leistung. */
    public const RULE_NOT_TAXABLE_DOMESTICALLY = '17';
    /** One Stop Shop — goods / electronic service / other service. */
    public const RULE_OSS_GOODS = '18';
    public const RULE_OSS_ELECTRONIC = '19';
    public const RULE_OSS_OTHER = '20';

    /**
     * Rates sevDesk accepts alongside each rule. null = depends on the
     * destination country (the OSS rules).
     */
    private const ALLOWED_RATES = [
        self::RULE_DOMESTIC => [0.0, 7.0, 19.0],
        self::RULE_EXPORT => [0.0],
        self::RULE_INTRA_EU => [0.0, 7.0, 19.0],
        self::RULE_TAX_FREE => [0.0],
        self::RULE_SMALL_BUSINESS => [0.0],
        self::RULE_NOT_TAXABLE_DOMESTICALLY => [0.0],
        self::RULE_OSS_GOODS => null,
        self::RULE_OSS_ELECTRONIC => null,
        self::RULE_OSS_OTHER => null,
    ];

    private const LABELS = [
        self::RULE_DOMESTIC => 'Umsatzsteuerpflichtige Umsätze',
        self::RULE_EXPORT => 'Ausfuhren',
        self::RULE_INTRA_EU => 'Innergemeinschaftliche Lieferungen',
        self::RULE_TAX_FREE => 'Steuerfreie Umsätze §4 UStG',
        self::RULE_SMALL_BUSINESS => 'Steuer nicht erhoben nach §19 UStG',
        self::RULE_NOT_TAXABLE_DOMESTICALLY => 'Nicht im Inland steuerbare Leistung',
        self::RULE_OSS_GOODS => 'One Stop Shop (Waren)',
        self::RULE_OSS_ELECTRONIC => 'One Stop Shop (elektronische Dienstleistung)',
        self::RULE_OSS_OTHER => 'One Stop Shop (sonstige Dienstleistung)',
    ];

    /**
     * Rules that were expressible in bookkeeping system 1.0.
     */
    private const LEGACY_TYPES = [
        self::RULE_DOMESTIC => 'default',
        self::RULE_INTRA_EU => 'eu',
        self::RULE_NOT_TAXABLE_DOMESTICALLY => 'noteu',
        self::RULE_SMALL_BUSINESS => 'ss',
        self::RULE_EXPORT => 'noteu',
        self::RULE_TAX_FREE => 'noteu',
        self::RULE_OSS_GOODS => 'eu',
        self::RULE_OSS_ELECTRONIC => 'eu',
        self::RULE_OSS_OTHER => 'eu',
    ];

    /**
     * Work out the rule for an order.
     */
    public function decide(Order $order): TaxDecision
    {
        $settings = Plugin::getInstance()->getSettings();
        $home = $settings->homeCountry();
        $country = $this->billingCountry($order) ?? $home;
        $vatId = $this->vatId($order);
        $hasVatId = $vatId !== '' && Vat::looksValid($vatId);

        // A Kleinunternehmer has exactly one rule and never charges VAT.
        if ($settings->taxScheme === Settings::SCHEME_SMALL) {
            return $this->make(self::RULE_SMALL_BUSINESS, Craft::t('sevvies', 'Kleinunternehmer under §19 UStG — no VAT is charged on any sale.'));
        }

        // Lite, or auto-detection switched off: everything is a domestic sale.
        if (!$settings->autoTaxRule || !Plugin::getInstance()->isPro()) {
            return $this->make(
                $settings->defaultTaxRule ?: self::RULE_DOMESTIC,
                Craft::t('sevvies', 'Automatic VAT rules are off; the default rule was used.'),
            );
        }

        if ($country === $home) {
            return $this->make(self::RULE_DOMESTIC, Craft::t('sevvies', 'Billing country {country} is your home country.', ['country' => $country]));
        }

        if (!Vat::isEu($country)) {
            return $this->make(self::RULE_EXPORT, Craft::t('sevvies', 'Billing country {country} is outside the EU, so this is an export.', ['country' => $country]));
        }

        // EU, business customer with a VAT ID — reverse charge.
        if ($hasVatId) {
            $vatCountry = Vat::country($vatId);

            if ($vatCountry !== null && $vatCountry !== $country) {
                $decision = $this->make(self::RULE_INTRA_EU, Craft::t('sevvies', 'VAT ID {vatId} was issued by {vatCountry} but the billing address is in {country}.', [
                    'vatId' => $vatId,
                    'vatCountry' => $vatCountry,
                    'country' => $country,
                ]));
                $decision->error = Craft::t('sevvies', 'The customer’s VAT ID country ({vatCountry}) does not match the billing country ({country}). Fix one of them before invoicing.', [
                    'vatCountry' => $vatCountry,
                    'country' => $country,
                ]);

                return $decision;
            }

            return $this->make(self::RULE_INTRA_EU, Craft::t('sevvies', 'EU business customer in {country} with VAT ID {vatId} — intra-community supply, reverse charge.', [
                'country' => $country,
                'vatId' => $vatId,
            ]));
        }

        // EU consumer.
        if ($settings->ossEnabled) {
            $rule = match ($settings->ossKind) {
                'electronic' => self::RULE_OSS_ELECTRONIC,
                'other' => self::RULE_OSS_OTHER,
                default => self::RULE_OSS_GOODS,
            };

            return $this->make($rule, Craft::t('sevvies', 'EU consumer in {country} and One Stop Shop is on, so {country} VAT applies.', ['country' => $country]));
        }

        if ($settings->reverseChargeRequiresVatId && $vatId !== '' && !Vat::looksValid($vatId)) {
            $decision = $this->make(self::RULE_DOMESTIC, Craft::t('sevvies', 'The supplied VAT ID {vatId} is not a valid format.', ['vatId' => $vatId]));
            $decision->error = Craft::t('sevvies', 'VAT ID “{vatId}” is not a valid {country} VAT number, so reverse charge cannot be applied.', [
                'vatId' => $vatId,
                'country' => $country,
            ]);

            return $decision;
        }

        return $this->make(self::RULE_DOMESTIC, Craft::t('sevvies', 'EU consumer in {country} with no VAT ID and OSS is off, so domestic VAT applies.', ['country' => $country]));
    }

    /**
     * Check the decided rule against the rates the order actually charged.
     *
     * A mismatch here almost always means Commerce's tax rules and the VAT rule
     * disagree — for instance an export that still charged 19%. Better to stop
     * than to file it.
     *
     * @param float[] $rates
     */
    public function validateRates(TaxDecision $decision, array $rates): ?string
    {
        foreach (array_unique($rates) as $rate) {
            if (!$decision->allowsRate((float)$rate)) {
                $allowed = implode(', ', array_map(
                    static fn(float $r): string => rtrim(rtrim(number_format($r, 1, '.', ''), '0'), '.') . '%',
                    $decision->allowedRates ?? [],
                ));

                return Craft::t('sevvies', 'This order charged {rate}% VAT, but sevDesk only accepts {allowed} under “{label}”. Check the Commerce tax rules for this destination.', [
                    'rate' => rtrim(rtrim(number_format((float)$rate, 2, '.', ''), '0'), '.'),
                    'allowed' => $allowed ?: '0%',
                    'label' => $decision->label,
                ]);
            }
        }

        return null;
    }

    /**
     * The VAT ID captured on the order, from the configured field or from the
     * billing address's organizationTaxId, which is where Craft's own address
     * field puts it.
     */
    public function vatId(Order $order): string
    {
        $settings = Plugin::getInstance()->getSettings();
        $handle = trim($settings->vatIdField);

        if ($handle !== '') {
            $value = null;

            try {
                $value = $order->getFieldValue($handle);
            } catch (\Throwable) {
                // Not a custom field on the order — try the billing address.
            }

            if (!$value) {
                $address = $order->getBillingAddress();

                if ($address) {
                    try {
                        $value = $address->getFieldValue($handle);
                    } catch (\Throwable) {
                        $value = $address->$handle ?? null;
                    }
                }
            }

            if (is_string($value) && trim($value) !== '') {
                return Vat::normalise($value);
            }
        }

        $address = $order->getBillingAddress();

        if ($address && !empty($address->organizationTaxId)) {
            return Vat::normalise($address->organizationTaxId);
        }

        return '';
    }

    /**
     * Two-letter billing country, or null when the order has no billing address.
     */
    public function billingCountry(Order $order): ?string
    {
        $address = $order->getBillingAddress();

        if (!$address || empty($address->countryCode)) {
            return null;
        }

        return strtoupper($address->countryCode);
    }

    /**
     * Every rule Sevvies can pick, for the settings screen.
     *
     * @return array<string,string>
     */
    public function ruleOptions(): array
    {
        return self::LABELS;
    }

    private function make(string $rule, string $reason): TaxDecision
    {
        $settings = Plugin::getInstance()->getSettings();
        $rates = self::ALLOWED_RATES[$rule] ?? null;

        $decision = new TaxDecision();
        $decision->rule = $rule;
        $decision->type = self::LEGACY_TYPES[$rule] ?? 'default';
        $decision->label = self::LABELS[$rule] ?? $rule;
        $decision->reason = $reason;
        $decision->allowedRates = $rates;
        $decision->zeroRated = $rates !== null && $rates === [0.0];
        $decision->text = $settings->taxText !== '' ? $settings->taxText : $this->defaultTaxText($rule);

        return $decision;
    }

    /**
     * The sentence a German invoice has to carry for the rule to stand up.
     */
    private function defaultTaxText(string $rule): string
    {
        return match ($rule) {
            self::RULE_INTRA_EU => 'Steuerfreie innergemeinschaftliche Lieferung — Reverse Charge, Steuerschuldnerschaft des Leistungsempfängers',
            self::RULE_EXPORT => 'Steuerfreie Ausfuhrlieferung',
            self::RULE_SMALL_BUSINESS => 'Gemäß §19 UStG wird keine Umsatzsteuer berechnet',
            self::RULE_TAX_FREE => 'Steuerfreier Umsatz nach §4 UStG',
            self::RULE_NOT_TAXABLE_DOMESTICALLY => 'Nicht im Inland steuerbare Leistung',
            self::RULE_OSS_GOODS,
            self::RULE_OSS_ELECTRONIC,
            self::RULE_OSS_OTHER => 'Besteuerung im Bestimmungsland (One Stop Shop)',
            default => 'Umsatzsteuer',
        };
    }
}
