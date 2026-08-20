<?php

namespace justinholtweb\sevvies\helpers;

/**
 * EU VAT facts that the tax engine needs.
 */
abstract class Vat
{
    /**
     * EU member states as of 2026. Northern Ireland trades as XI for goods.
     */
    public const EU = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR',
        'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO',
        'SE', 'SI', 'SK',
    ];

    /**
     * VAT ID formats per country. Deliberately structural — this proves the
     * number is well formed, it does not prove it is registered. Only VIES can
     * do that, and an outage there must never block an order.
     */
    private const PATTERNS = [
        'AT' => '/^ATU\d{8}$/',
        'BE' => '/^BE[01]\d{9}$/',
        'BG' => '/^BG\d{9,10}$/',
        'CY' => '/^CY\d{8}[A-Z]$/',
        'CZ' => '/^CZ\d{8,10}$/',
        'DE' => '/^DE\d{9}$/',
        'DK' => '/^DK\d{8}$/',
        'EE' => '/^EE\d{9}$/',
        'ES' => '/^ES[A-Z0-9]\d{7}[A-Z0-9]$/',
        'FI' => '/^FI\d{8}$/',
        'FR' => '/^FR[A-Z0-9]{2}\d{9}$/',
        'GR' => '/^(EL|GR)\d{9}$/',
        'HR' => '/^HR\d{11}$/',
        'HU' => '/^HU\d{8}$/',
        'IE' => '/^IE(\d{7}[A-Z]{1,2}|\d[A-Z*+]\d{5}[A-Z])$/',
        'IT' => '/^IT\d{11}$/',
        'LT' => '/^LT(\d{9}|\d{12})$/',
        'LU' => '/^LU\d{8}$/',
        'LV' => '/^LV\d{11}$/',
        'MT' => '/^MT\d{8}$/',
        'NL' => '/^NL\d{9}B\d{2}$/',
        'PL' => '/^PL\d{10}$/',
        'PT' => '/^PT\d{9}$/',
        'RO' => '/^RO\d{2,10}$/',
        'SE' => '/^SE\d{12}$/',
        'SI' => '/^SI\d{8}$/',
        'SK' => '/^SK\d{10}$/',
        'XI' => '/^XI(\d{9}|\d{12}|(GD|HA)\d{3})$/',
    ];

    public static function isEu(?string $country): bool
    {
        $country = strtoupper(trim((string)$country));

        return $country !== '' && (in_array($country, self::EU, true) || $country === 'XI');
    }

    /**
     * Strip spaces, dots and dashes and upper-case. sevDesk stores what we send,
     * so send the canonical form.
     */
    public static function normalise(?string $vatId): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$vatId) ?? '');
    }

    /**
     * Structurally valid VAT ID? Greece writes EL on the VAT ID and GR on the
     * address, so the two are treated as the same country here.
     */
    public static function looksValid(?string $vatId): bool
    {
        $vatId = self::normalise($vatId);

        if (strlen($vatId) < 4) {
            return false;
        }

        $prefix = substr($vatId, 0, 2);
        $prefix = $prefix === 'EL' ? 'GR' : $prefix;

        if (!isset(self::PATTERNS[$prefix])) {
            return false;
        }

        return (bool)preg_match(self::PATTERNS[$prefix], $vatId);
    }

    /**
     * Country the VAT ID belongs to, or null.
     */
    public static function country(?string $vatId): ?string
    {
        $vatId = self::normalise($vatId);

        if (strlen($vatId) < 2) {
            return null;
        }

        $prefix = substr($vatId, 0, 2);

        return $prefix === 'EL' ? 'GR' : $prefix;
    }
}
