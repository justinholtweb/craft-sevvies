<?php

namespace justinholtweb\sevvies\services;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\models\LineItem;
use craft\db\Query;
use craft\elements\Address;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\sevvies\db\Table;
use justinholtweb\sevvies\errors\ApiException;
use justinholtweb\sevvies\errors\ReconciliationException;
use justinholtweb\sevvies\helpers\Money;
use justinholtweb\sevvies\models\InvoiceDraft;
use justinholtweb\sevvies\models\Settings;
use justinholtweb\sevvies\Plugin;
use justinholtweb\sevvies\records\InvoiceRecord;
use yii\base\Component;

/**
 * Turning Commerce orders into sevDesk invoices.
 *
 * Two invariants hold this plugin together:
 *
 * 1. `build()` is the only place an order becomes a sevDesk payload. The CP
 *    preview, the console preview and the live sync all call it, so a preview
 *    is byte-identical to what gets sent.
 * 2. `sync()` is the only place an invoice is created, and it runs behind the
 *    unique index on `orderId`. An order cannot be invoiced twice, however many
 *    times a status flips or a queue job retries.
 */
class Invoices extends Component
{
    public const STATE_PENDING = 'pending';
    public const STATE_CREATED = 'created';
    public const STATE_SENT = 'sent';
    public const STATE_BOOKED = 'booked';
    public const STATE_FAILED = 'failed';
    public const STATE_SKIPPED = 'skipped';
    public const STATE_BLOCKED = 'blocked';

    /** sevDesk invoice status codes. */
    public const SEVDESK_DRAFT = '100';
    public const SEVDESK_OPEN = '200';
    public const SEVDESK_PAID = '1000';

    // ——————————————————————————————————————————————————————————————
    //  Building
    // ——————————————————————————————————————————————————————————————

    /**
     * Build the complete sevDesk payload for an order.
     *
     * This never talks to sevDesk except to resolve ids it cannot guess
     * (country, contact person), so it is safe to call for a preview.
     */
    public function build(Order $order, ?int $contactId = null): InvoiceDraft
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $draft = new InvoiceDraft();
        $draft->orderId = (int)$order->id;
        $draft->orderReference = (string)($order->reference ?: $order->number);
        $draft->currency = strtoupper((string)($order->currency ?: 'EUR'));
        $draft->expectedGross = Money::round((float)$order->getTotalPrice());

        $decision = $plugin->tax->decide($order);
        $draft->decision = $decision;

        if ($decision->error !== null) {
            $draft->blockers[] = $decision->error;
        }

        // — Positions ——————————————————————————————————————————————

        $rates = [];

        foreach ($order->getLineItems() as $lineItem) {
            $position = $this->positionFromLineItem($lineItem, $settings);

            if ($position === null) {
                continue;
            }

            $rates[] = $position['taxRate'];
            $draft->positions[] = $position;
        }

        $shipping = $this->shippingPosition($order, $settings);

        if ($shipping !== null) {
            $rates[] = $shipping['taxRate'];
            $draft->positions[] = $shipping;
        }

        foreach ($this->otherAdjustmentPositions($order, $settings) as $position) {
            $rates[] = $position['taxRate'];
            $draft->positions[] = $position;
        }

        // — Discounts ——————————————————————————————————————————————

        $discountTotal = $this->orderDiscountTotal($order);

        if ($discountTotal < 0) {
            if ($settings->sendDiscounts) {
                $draft->discounts[] = [
                    'discount' => true,
                    'text' => Craft::t('sevvies', 'Discount'),
                    'percentage' => false,
                    'value' => Money::round(abs($discountTotal)),
                    'objectName' => 'Discounts',
                    'mapAll' => true,
                ];
            } else {
                $this->foldDiscountIntoPositions($draft, $discountTotal);
            }
        }

        if ($draft->positions === []) {
            $draft->blockers[] = Craft::t('sevvies', 'This order has nothing to invoice.');
        }

        // — Rates against the rule —————————————————————————————————

        $rateError = $plugin->tax->validateRates($decision, $rates);

        if ($rateError !== null) {
            $draft->blockers[] = $rateError;
        }

        // — Arithmetic —————————————————————————————————————————————

        $this->computeTotals($draft);

        if (!$draft->balances()) {
            $draft->warnings[] = Craft::t('sevvies', 'These positions come to {computed} but Commerce charged {expected}. Sevvies will not create the invoice unless they agree.', [
                'computed' => $this->format($draft->computedGross, $draft->currency),
                'expected' => $this->format($draft->expectedGross, $draft->currency),
            ]);
            $draft->blockers[] = Craft::t('sevvies', 'The invoice total would be {difference} out.', [
                'difference' => $this->format($draft->difference(), $draft->currency),
            ]);
        }

        // — The invoice object —————————————————————————————————————

        $draft->invoice = $this->invoiceObject($order, $draft, $contactId);

        // sevDesk requires a country on every document. If the list came back
        // and this country is not in it, say so here rather than letting
        // sevDesk answer with a 400 that names no field.
        $billingCountry = $order->getBillingAddress()?->countryCode;

        if ($billingCountry && !isset($draft->invoice['addressCountry'])) {
            $draft->blockers[] = Craft::t('sevvies', 'sevDesk does not recognise the country “{code}” on the billing address.', [
                'code' => strtoupper((string)$billingCountry),
            ]);
        }

        if (!isset($draft->invoice['contactPerson'])) {
            $draft->blockers[] = Craft::t('sevvies', 'No sevDesk contact person could be found. Set one in Sevvies’ settings.');
        }

        if ($contactId === null) {
            $draft->warnings[] = Craft::t('sevvies', 'No sevDesk contact is attached, so the invoice cannot move beyond draft.');
        }

        return $draft;
    }

    /**
     * The sevDesk `invoice` object.
     */
    private function invoiceObject(Order $order, InvoiceDraft $draft, ?int $contactId): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $address = $order->getBillingAddress();
        $date = $order->dateOrdered instanceof DateTime ? $order->dateOrdered : new DateTime();

        $invoice = [
            'objectName' => 'Invoice',
            'mapAll' => true,
            'invoiceType' => $settings->invoiceType,
            // sevDesk only accepts new invoices as drafts. Status is moved on
            // afterwards by sendViaEmail / sendBy / bookAmount.
            'status' => self::SEVDESK_DRAFT,
            'invoiceDate' => $date->format('d.m.Y'),
            'currency' => $draft->currency,
            'timeToPay' => $settings->timeToPay,
            'discount' => 0,
            'showNet' => $settings->showNet(),
            'smallSettlement' => $settings->taxScheme === Settings::SCHEME_SMALL,
            'taxText' => $draft->decision?->text ?? 'Umsatzsteuer',
            'address' => $this->addressBlock($order, $address),
            'customerInternalNote' => $this->internalNote($order),
            'header' => $this->render($settings->headerTemplate, $order) ?: null,
            'headText' => $this->render($settings->headTextTemplate, $order) ?: null,
            'footText' => $this->render($settings->footTextTemplate, $order) ?: null,
        ];

        // Bookkeeping system 2.0 wants taxRule; 1.0 only understands taxType.
        // Sending both is safe and survives an account being migrated between
        // the two while orders are in flight.
        $invoice['taxRule'] = ['id' => $draft->decision?->rule ?? '1', 'objectName' => 'TaxRule'];
        $invoice['taxType'] = $draft->decision?->type ?? 'default';
        $invoice['taxRate'] = 0;

        if ($contactId !== null) {
            $invoice['contact'] = ['id' => $contactId, 'objectName' => 'Contact'];
        }

        $contactPersonId = $this->contactPersonId();

        if ($contactPersonId !== null) {
            $invoice['contactPerson'] = ['id' => $contactPersonId, 'objectName' => 'SevUser'];
        }

        $countryId = $address ? $plugin->meta->countryId($address->countryCode) : null;

        if ($countryId !== null) {
            $invoice['addressCountry'] = ['id' => $countryId, 'objectName' => 'StaticCountry'];
        }

        // One Stop Shop is decided on where the goods go, not where the bill is
        // sent, so the shipping country travels with the document.
        $shippingCountry = $order->getShippingAddress()?->countryCode;

        if ($settings->ossEnabled && $shippingCountry && $address && strtoupper($shippingCountry) !== strtoupper((string)$address->countryCode)) {
            $shippingCountryId = $plugin->meta->countryId($shippingCountry);

            if ($shippingCountryId !== null) {
                $invoice['deliveryAddressCountry'] = ['id' => $shippingCountryId, 'objectName' => 'StaticCountry'];
            }
        }

        return array_filter($invoice, static fn($value): bool => $value !== null);
    }

    // ——————————————————————————————————————————————————————————————
    //  Positions
    // ——————————————————————————————————————————————————————————————

    private function positionFromLineItem(LineItem $lineItem, Settings $settings): ?array
    {
        $qty = (float)$lineItem->qty;

        if ($qty <= 0) {
            return null;
        }

        $gross = Money::round((float)$lineItem->getTotal());
        $tax = Money::round($this->lineItemTax($lineItem));
        $net = Money::round($gross - $tax);
        $rate = $this->rateFor($net, $tax);

        $basis = $settings->showNet() ? $net : $gross;

        return [
            'objectName' => 'InvoicePos',
            'mapAll' => true,
            'quantity' => $qty,
            // Unit price at four places: sevDesk multiplies it out, so trimming
            // to two here would lose money on quantities like 3 × 11.203333.
            'price' => Money::round($basis / $qty, 4),
            'name' => $this->positionName($lineItem),
            'text' => $this->positionText($lineItem, $settings),
            'taxRate' => $rate,
            'unity' => ['id' => $settings->unityId, 'objectName' => 'Unity'],
            '_gross' => $gross,
            '_net' => $net,
            '_tax' => $tax,
        ];
    }

    /**
     * All tax on a line, whether Commerce added it or it was already in the price.
     */
    private function lineItemTax(LineItem $lineItem): float
    {
        $tax = 0.0;

        foreach ($lineItem->getAdjustments() as $adjustment) {
            if ($adjustment->type === 'tax') {
                $tax += (float)$adjustment->amount;
            }
        }

        return $tax;
    }

    /**
     * Shipping becomes its own position, carrying its own tax.
     */
    private function shippingPosition(Order $order, Settings $settings): ?array
    {
        $gross = 0.0;
        $tax = 0.0;
        $name = null;

        foreach ($order->getOrderAdjustments() as $adjustment) {
            // Included shipping is already inside a line item's price.
            if ($adjustment->type === 'shipping' && !$adjustment->included) {
                $gross += (float)$adjustment->amount;
                $name ??= $adjustment->name;
            }
        }

        // Order-level tax is, in practice, tax on shipping.
        foreach ($order->getOrderAdjustments() as $adjustment) {
            if ($adjustment->type === 'tax') {
                $tax += (float)$adjustment->amount;

                if (!$adjustment->included) {
                    $gross += (float)$adjustment->amount;
                }
            }
        }

        if (Money::same($gross, 0.0) && Money::same($tax, 0.0)) {
            return null;
        }

        $gross = Money::round($gross);
        $tax = Money::round($tax);
        $net = Money::round($gross - $tax);

        return [
            'objectName' => 'InvoicePos',
            'mapAll' => true,
            'quantity' => 1,
            'price' => $settings->showNet() ? $net : $gross,
            'name' => $settings->shippingName ?: ($name ?: Craft::t('sevvies', 'Shipping')),
            'text' => null,
            'taxRate' => $this->rateFor($net, $tax),
            'unity' => ['id' => $settings->unityId, 'objectName' => 'Unity'],
            '_gross' => $gross,
            '_net' => $net,
            '_tax' => $tax,
        ];
    }

    /**
     * Order-level adjustments that are neither shipping, tax nor discount —
     * surcharges, fees, whatever a third-party adjuster added. They become
     * positions rather than vanishing into a rounding difference.
     *
     * @return array[]
     */
    private function otherAdjustmentPositions(Order $order, Settings $settings): array
    {
        $positions = [];

        foreach ($order->getOrderAdjustments() as $adjustment) {
            if (in_array($adjustment->type, ['shipping', 'tax', 'discount'], true) || $adjustment->included) {
                continue;
            }

            $amount = Money::round((float)$adjustment->amount);

            if (Money::same($amount, 0.0)) {
                continue;
            }

            $positions[] = [
                'objectName' => 'InvoicePos',
                'mapAll' => true,
                'quantity' => 1,
                'price' => $amount,
                'name' => $adjustment->name ?: Craft::t('sevvies', 'Adjustment'),
                'text' => $adjustment->description ?: null,
                'taxRate' => 0.0,
                'unity' => ['id' => $settings->unityId, 'objectName' => 'Unity'],
                '_gross' => $amount,
                '_net' => $amount,
                '_tax' => 0.0,
            ];
        }

        return $positions;
    }

    /**
     * Order-level discounts, as a negative number.
     */
    private function orderDiscountTotal(Order $order): float
    {
        $total = 0.0;

        foreach ($order->getOrderAdjustments() as $adjustment) {
            if ($adjustment->type === 'discount' && !$adjustment->included) {
                $total += (float)$adjustment->amount;
            }
        }

        return Money::round($total);
    }

    /**
     * Spread an order-level discount across the positions in proportion to
     * their value, for merchants who would rather not show a discount line.
     */
    private function foldDiscountIntoPositions(InvoiceDraft $draft, float $discountTotal): void
    {
        $base = 0.0;

        foreach ($draft->positions as $position) {
            $base += (float)$position['_gross'];
        }

        if ($base <= 0) {
            return;
        }

        $factor = 1 + ($discountTotal / $base);

        foreach ($draft->positions as $index => $position) {
            $qty = (float)$position['quantity'];
            $gross = Money::round((float)$position['_gross'] * $factor);
            $tax = Money::round((float)$position['_tax'] * $factor);
            $net = Money::round($gross - $tax);
            $basis = Plugin::getInstance()->getSettings()->showNet() ? $net : $gross;

            $draft->positions[$index]['price'] = Money::round($basis / max($qty, 1), 4);
            $draft->positions[$index]['_gross'] = $gross;
            $draft->positions[$index]['_net'] = $net;
            $draft->positions[$index]['_tax'] = $tax;
        }
    }

    /**
     * Sum the draft the way sevDesk will.
     */
    private function computeTotals(InvoiceDraft $draft): void
    {
        $net = 0.0;
        $tax = 0.0;
        $gross = 0.0;

        foreach ($draft->positions as $position) {
            $net += (float)$position['_net'];
            $tax += (float)$position['_tax'];
            $gross += (float)$position['_gross'];
        }

        foreach ($draft->discounts as $discount) {
            $gross -= (float)$discount['value'];
            // sevDesk applies a discount to the gross total and derives the
            // net and tax shares from it.
            $share = $gross > 0 ? (float)$discount['value'] : 0.0;
            $ratio = ($net + $tax) > 0 ? $net / ($net + $tax) : 1.0;
            $net -= Money::round($share * $ratio);
            $tax -= Money::round($share * (1 - $ratio));
        }

        $draft->computedNet = Money::round($net);
        $draft->computedTax = Money::round($tax);
        $draft->computedGross = Money::round($gross);
    }

    /**
     * The VAT rate implied by a net and tax pair, snapped to the German rates
     * when it is within a rounding wobble of one of them.
     */
    private function rateFor(float $net, float $tax): float
    {
        if ($net <= 0 || $tax <= 0) {
            return 0.0;
        }

        $rate = ($tax / $net) * 100;

        foreach ([19.0, 7.0, 0.0, 5.0, 16.0] as $known) {
            if (abs($rate - $known) < 0.15) {
                return $known;
            }
        }

        return Money::round($rate);
    }

    // ——————————————————————————————————————————————————————————————
    //  Sending
    // ——————————————————————————————————————————————————————————————

    /**
     * Create the invoice in sevDesk for this order.
     *
     * Safe to call repeatedly: an order that already has an invoice is left
     * alone. Returns the invoice row.
     */
    public function sync(Order $order, bool $force = false): InvoiceRecord
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $record = $this->recordFor($order->id) ?? $this->claim($order);

        if ($record->sevdeskId && !$force) {
            return $record;
        }

        $record->attempts = (int)$record->attempts + 1;

        try {
            $contactId = $plugin->contacts->resolve($order);
            $draft = $this->build($order, $contactId);

            $record->taxRule = $draft->decision?->rule;
            $record->taxType = $draft->decision?->type;
            $record->taxReason = $draft->decision?->reason ? StringHelper::safeTruncate($draft->decision->reason, 250) : null;
            $record->currency = $draft->currency;
            $record->expectedGross = $draft->expectedGross;
            $record->contactId = $contactId;
            $record->payloadHash = $draft->hash();
            $record->payload = Json::encode($draft->payload());

            if (!$draft->isSendable()) {
                $record->state = self::STATE_BLOCKED;
                $record->lastError = implode(' ', $draft->blockers);
                $record->save(false);

                $plugin->log->note(
                    Craft::t('sevvies', 'Order {reference} was not invoiced: {reason}', [
                        'reference' => $draft->orderReference,
                        'reason' => implode(' ', $draft->blockers),
                    ]),
                    $order->id,
                    false,
                    'invoice',
                );

                return $record;
            }

            if ($settings->dryRun) {
                $record->state = self::STATE_SKIPPED;
                $record->lastError = null;
                $record->save(false);

                $plugin->log->note(
                    Craft::t('sevvies', 'Dry run: order {reference} would have been invoiced.', ['reference' => $draft->orderReference]),
                    $order->id,
                    true,
                    'invoice',
                    Json::encode($draft->payload(), JSON_PRETTY_PRINT),
                );

                return $record;
            }

            $created = $plugin->api->post('Invoice/Factory/saveInvoice', $draft->payload(), $order->id);
            $invoice = $created['invoice'] ?? $created;

            $record->sevdeskId = isset($invoice['id']) ? (int)$invoice['id'] : null;
            $record->invoiceNumber = $invoice['invoiceNumber'] ?? null;
            $record->invoiceType = (string)($invoice['invoiceType'] ?? $settings->invoiceType);
            $record->sevdeskStatus = isset($invoice['status']) ? (string)$invoice['status'] : null;
            $record->sumNet = isset($invoice['sumNet']) ? Money::toFloat($invoice['sumNet']) : null;
            $record->sumTax = isset($invoice['sumTax']) ? Money::toFloat($invoice['sumTax']) : null;
            $record->sumGross = isset($invoice['sumGross']) ? Money::toFloat($invoice['sumGross']) : null;
            $record->state = self::STATE_CREATED;
            $record->lastError = null;
            $record->save(false);

            $this->reconcile($record, $draft);

            $plugin->log->note(
                Craft::t('sevvies', 'Created sevDesk invoice {number} for order {reference}.', [
                    'number' => $record->invoiceNumber ?: (string)$record->sevdeskId,
                    'reference' => $draft->orderReference,
                ]),
                $order->id,
                true,
                'invoice',
            );

            $this->afterCreate($order, $record);
        } catch (ReconciliationException $e) {
            $record->state = self::STATE_BLOCKED;
            $record->reconciled = false;
            $record->lastError = $e->getMessage();
            $record->save(false);

            $plugin->log->note($e->getMessage(), $order->id, false, 'reconcile');
        } catch (ApiException $e) {
            $record->state = self::STATE_FAILED;
            $record->lastError = $e->getMessage();
            $record->save(false);

            throw $e;
        }

        return $record;
    }

    /**
     * sevDesk has now told us what it thinks the invoice is worth. If that is
     * not what Commerce charged, say so loudly — an invoice for the wrong
     * amount is worse than a missing one.
     */
    private function reconcile(InvoiceRecord $record, InvoiceDraft $draft): void
    {
        $actual = $record->sumGross === null ? null : (float)$record->sumGross;

        if ($actual === null) {
            $record->reconciled = false;
            $record->save(false);

            return;
        }

        if (Money::same($actual, $draft->expectedGross)) {
            $record->reconciled = true;
            $record->save(false);

            return;
        }

        $hint = $this->priceBasisHint($actual, $draft);

        throw new ReconciliationException(
            Craft::t('sevvies', 'sevDesk booked {actual} for order {reference} but Commerce charged {expected}.{hint}', [
                'actual' => $this->format($actual, $draft->currency),
                'expected' => $this->format($draft->expectedGross, $draft->currency),
                'reference' => $draft->orderReference,
                'hint' => $hint === null ? '' : ' ' . $hint,
            ]),
            $draft->expectedGross,
            $actual,
            $record->sevdeskId ? (int)$record->sevdeskId : null,
        );
    }

    /**
     * The commonest cause of a mismatch is the account reading position prices
     * as gross when Sevvies sent net, or the reverse — and the ratio between
     * the two totals says which.
     */
    private function priceBasisHint(float $actual, InvoiceDraft $draft): ?string
    {
        if ($draft->computedNet <= 0) {
            return null;
        }

        $impliedRate = ($draft->computedTax / $draft->computedNet) * 100;

        if ($impliedRate < 0.5) {
            return null;
        }

        $ifGross = Money::netOf($draft->expectedGross, $impliedRate);
        $ifNet = Money::grossOf($draft->expectedGross, $impliedRate);

        if (Money::same($actual, $ifNet, 0.02) || Money::same($actual, $ifGross, 0.02)) {
            return Craft::t('sevvies', 'That is the same total with VAT added or removed, which means the “Position prices” setting does not match your sevDesk account.');
        }

        return null;
    }

    /**
     * Everything that happens once the document exists — sending, booking,
     * archiving. None of it is allowed to undo the invoice.
     */
    private function afterCreate(Order $order, InvoiceRecord $record): void
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$record->sevdeskId) {
            return;
        }

        try {
            if ($settings->sendMode !== Settings::SEND_NONE) {
                $plugin->documents->send($order, $record);
            }

            if ($settings->archivePdf && $plugin->isPro()) {
                $plugin->documents->archive($order, $record);
            }

            if ($settings->bookPayments && $plugin->isPro() && $order->getIsPaid()) {
                $plugin->payments->book($order, $record);
            }
        } catch (ApiException $e) {
            // The invoice exists and is correct; the follow-up failed. Record it
            // and let the merchant retry from the CP rather than tearing down a
            // valid bookkeeping document.
            $record->lastError = $e->getMessage();
            $record->save(false);

            $plugin->log->note(
                Craft::t('sevvies', 'The invoice was created but a follow-up step failed.'),
                $order->id,
                false,
                'invoice',
                $e->getMessage(),
            );
        }
    }

    // ——————————————————————————————————————————————————————————————
    //  Rows
    // ——————————————————————————————————————————————————————————————

    /**
     * Reserve the row for this order. The unique index means a second caller
     * loses the race and reads the winner's row instead of creating a twin.
     */
    public function claim(Order $order): InvoiceRecord
    {
        $record = new InvoiceRecord();
        $record->orderId = (int)$order->id;
        $record->state = self::STATE_PENDING;
        $record->invoiceType = Plugin::getInstance()->getSettings()->invoiceType;

        try {
            $record->save(false);
        } catch (\Throwable) {
            $existing = $this->recordFor($order->id);

            if ($existing === null) {
                throw new ApiException(Craft::t('sevvies', 'Could not reserve an invoice row for this order.'));
            }

            return $existing;
        }

        return $record;
    }

    public function recordFor(?int $orderId): ?InvoiceRecord
    {
        if (!$orderId) {
            return null;
        }

        return InvoiceRecord::findOne(['orderId' => $orderId]);
    }

    /**
     * Detach an order from its sevDesk invoice locally. The document in sevDesk
     * is never touched — deleting bookkeeping documents is not Sevvies' call.
     */
    public function forget(int $orderId): bool
    {
        return InvoiceRecord::deleteAll(['orderId' => $orderId]) > 0;
    }

    /**
     * Orders that should have an invoice and do not.
     *
     * @return int[]
     */
    public function pendingOrderIds(int $limit = 100): array
    {
        $done = (new Query())
            ->select(['orderId'])
            ->from([Table::INVOICES])
            ->where(['not', ['sevdeskId' => null]]);

        return Order::find()
            ->isCompleted(true)
            ->andWhere(['not in', 'elements.id', $done])
            ->limit($limit)
            ->orderBy(['commerce_orders.dateOrdered' => SORT_ASC])
            ->ids();
    }

    // ——————————————————————————————————————————————————————————————
    //  Helpers
    // ——————————————————————————————————————————————————————————————

    private function contactPersonId(): ?int
    {
        try {
            return Plugin::getInstance()->meta->contactPersonId();
        } catch (ApiException) {
            return Plugin::getInstance()->getSettings()->contactPersonId;
        }
    }

    private function positionName(LineItem $lineItem): string
    {
        $name = trim((string)$lineItem->getDescription());

        if ($name === '') {
            $name = trim((string)($lineItem->getPurchasable()?->getDescription() ?? ''));
        }

        return $name !== '' ? StringHelper::safeTruncate($name, 190) : Craft::t('sevvies', 'Item');
    }

    private function positionText(LineItem $lineItem, Settings $settings): ?string
    {
        $parts = [];

        if ($settings->includeSku && $lineItem->getSku()) {
            $parts[] = Craft::t('sevvies', 'SKU: {sku}', ['sku' => $lineItem->getSku()]);
        }

        foreach ((array)$lineItem->getOptions() as $key => $value) {
            if (is_scalar($value)) {
                $parts[] = $key . ': ' . $value;
            }
        }

        if ($lineItem->note) {
            $parts[] = (string)$lineItem->note;
        }

        return $parts === [] ? null : implode("\n", $parts);
    }

    /**
     * The address block printed on the document. sevDesk takes one string with
     * line breaks and prints it verbatim, so this is the merchant's whole
     * control over how the customer's address appears.
     */
    private function addressBlock(Order $order, ?Address $address): string
    {
        if ($address === null) {
            return '';
        }

        $lines = array_filter([
            trim((string)$address->organization),
            trim((string)$address->fullName),
            trim((string)$address->addressLine1),
            trim((string)$address->addressLine2),
            trim(trim((string)$address->postalCode) . ' ' . trim((string)$address->locality)),
            $this->countryName($address),
        ], static fn(string $line): bool => $line !== '');

        return implode("\n", array_unique($lines));
    }

    private function countryName(Address $address): string
    {
        $code = strtoupper((string)$address->countryCode);

        if ($code === '' || $code === Plugin::getInstance()->getSettings()->homeCountry()) {
            // A domestic invoice does not print its own country.
            return '';
        }

        try {
            return Craft::$app->getAddresses()->getCountryRepository()->get($code)?->getName() ?: $code;
        } catch (\Throwable) {
            return $code;
        }
    }

    /**
     * The order reference goes on the document so a payment can be matched back
     * to a Commerce order without opening either system.
     */
    private function internalNote(Order $order): string
    {
        return Craft::t('sevvies', 'Craft Commerce order {reference}', [
            'reference' => (string)($order->reference ?: $order->number),
        ]);
    }

    /**
     * Render a Twig setting against the order.
     */
    public function render(?string $template, Order $order): string
    {
        $template = trim((string)$template);

        if ($template === '') {
            return '';
        }

        try {
            return (string)Craft::$app->getView()->renderObjectTemplate($template, $order, [
                'order' => $order,
                'invoice' => $this->recordFor($order->id),
            ]);
        } catch (\Throwable $e) {
            Plugin::getInstance()->log->note(
                Craft::t('sevvies', 'A Sevvies template could not be rendered.'),
                $order->id,
                false,
                'template',
                $e->getMessage(),
            );

            return '';
        }
    }

    private function format(float $amount, string $currency): string
    {
        return Craft::$app->getFormatter()->asCurrency($amount, $currency);
    }
}
