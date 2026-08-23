<?php

namespace justinholtweb\sevvies\services;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\models\Transaction;
use DateTime;
use justinholtweb\sevvies\errors\ApiException;
use justinholtweb\sevvies\helpers\Money;
use justinholtweb\sevvies\models\Settings;
use justinholtweb\sevvies\Plugin;
use justinholtweb\sevvies\records\CreditRecord;
use justinholtweb\sevvies\records\InvoiceRecord;
use yii\base\Component;

/**
 * Money moving after the invoice exists: payments booked against it, and
 * refunds mirrored as credit notes.
 *
 * Booking is the step that closes an invoice in sevDesk, so it is guarded the
 * same way invoice creation is — `bookedAt` on the row means it has happened
 * and will not happen again.
 */
class Payments extends Component
{
    /**
     * Book the order's payment against its sevDesk invoice.
     *
     * @throws ApiException
     */
    public function book(Order $order, ?InvoiceRecord $record = null): bool
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $record ??= $plugin->invoices->recordFor($order->id);

        if ($record === null || !$record->sevdeskId) {
            return false;
        }

        if ($record->bookedAt !== null) {
            return false;
        }

        if (!$settings->checkAccountId) {
            $plugin->log->note(
                Craft::t('sevvies', 'No sevDesk check account is chosen, so the payment was not booked.'),
                $order->id,
                false,
                'payment',
            );

            return false;
        }

        $amount = Money::round((float)$order->getTotalPaid());

        if ($amount <= 0) {
            return false;
        }

        // An invoice has to be out of draft before an amount can be booked
        // against it. Marking it sent is the honest way to get there.
        if ($record->sentAt === null) {
            $plugin->documents->markSent($order, $record);
        }

        $paidDate = $order->datePaid instanceof DateTime ? $order->datePaid : new DateTime();

        $body = [
            'amount' => $amount,
            // sevDesk wants a unix timestamp here, not the d.m.Y it uses elsewhere.
            'date' => $paidDate->getTimestamp(),
            'type' => Money::same($amount, (float)($record->sumGross ?? $record->expectedGross ?? 0.0))
                ? 'FULL_PAYMENT'
                : 'N',
            'checkAccount' => [
                'id' => $settings->checkAccountId,
                'objectName' => 'CheckAccount',
            ],
            'createFeed' => false,
        ];

        $plugin->api->put("Invoice/{$record->sevdeskId}/bookAmount", $body, $order->id);

        $record->bookedAt = new DateTime();
        $record->state = Invoices::STATE_BOOKED;
        $record->sevdeskStatus = Invoices::SEVDESK_PAID;
        $record->save(false);

        $plugin->log->note(
            Craft::t('sevvies', 'Booked {amount} against invoice {number}.', [
                'amount' => Craft::$app->getFormatter()->asCurrency($amount, (string)($record->currency ?: 'EUR')),
                'number' => $record->invoiceNumber ?: (string)$record->sevdeskId,
            ]),
            $order->id,
            true,
            'payment',
        );

        return true;
    }

    /**
     * Mirror a Commerce refund into sevDesk as a credit note.
     *
     * A full refund reverses the invoice wholesale; a partial one gets a credit
     * note with a single position for the amount returned, filed against the
     * original invoice so the two stay linked in the books.
     *
     * @throws ApiException
     */
    public function refund(Order $order, Transaction $transaction): ?CreditRecord
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if ($settings->refundMode !== Settings::REFUND_CREDIT_NOTE) {
            return null;
        }

        $record = $plugin->invoices->recordFor($order->id);

        if ($record === null || !$record->sevdeskId) {
            return null;
        }

        $amount = Money::round(abs((float)$transaction->amount));

        if ($amount <= 0) {
            return null;
        }

        $refundKey = 'txn:' . $transaction->id;

        if (CreditRecord::findOne(['orderId' => $order->id, 'refundKey' => $refundKey]) !== null) {
            return null;
        }

        $credit = new CreditRecord();
        $credit->orderId = (int)$order->id;
        $credit->refundKey = $refundKey;
        $credit->amount = $amount;
        $credit->currency = (string)($record->currency ?: $order->currency);

        try {
            $credit->save(false);
        } catch (\Throwable) {
            // Lost the race with another handler for the same refund.
            return null;
        }

        $invoiceTotal = (float)($record->sumGross ?? $record->expectedGross ?? 0.0);
        $isFull = Money::same($amount, $invoiceTotal);

        try {
            $created = $isFull
                ? $this->fullCreditNote($record, $order->id)
                : $this->partialCreditNote($order, $record, $amount);

            $note = $created['creditNote'] ?? $created;

            $credit->sevdeskId = isset($note['id']) ? (int)$note['id'] : null;
            $credit->creditNoteNumber = $note['creditNoteNumber'] ?? null;
            $credit->state = Invoices::STATE_CREATED;
            $credit->save(false);

            $plugin->log->note(
                Craft::t('sevvies', 'Created credit note {number} for {amount}.', [
                    'number' => $credit->creditNoteNumber ?: (string)$credit->sevdeskId,
                    'amount' => Craft::$app->getFormatter()->asCurrency($amount, (string)$credit->currency),
                ]),
                $order->id,
                true,
                'refund',
            );
        } catch (ApiException $e) {
            $credit->state = Invoices::STATE_FAILED;
            $credit->lastError = $e->getMessage();
            $credit->save(false);

            throw $e;
        }

        return $credit;
    }

    /**
     * @throws ApiException
     */
    private function fullCreditNote(InvoiceRecord $record, int $orderId): array
    {
        $created = Plugin::getInstance()->api->post('CreditNote/Factory/createFromInvoice', [
            'invoice' => ['id' => (int)$record->sevdeskId, 'objectName' => 'Invoice'],
        ], $orderId);

        return is_array($created) ? $created : [];
    }

    /**
     * @throws ApiException
     */
    private function partialCreditNote(Order $order, InvoiceRecord $record, float $amount): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $decision = $plugin->tax->decide($order);

        $rate = $this->invoiceTaxRate($record);
        $net = $settings->showNet() ? Money::round(Money::netOf($amount, $rate)) : $amount;

        $creditNote = [
            'objectName' => 'CreditNote',
            'mapAll' => true,
            'creditNoteDate' => (new DateTime())->format('d.m.Y'),
            'status' => Invoices::SEVDESK_DRAFT,
            'header' => Craft::t('sevvies', 'Credit note for invoice {number}', [
                'number' => $record->invoiceNumber ?: (string)$record->sevdeskId,
            ]),
            'currency' => (string)($record->currency ?: $order->currency),
            'showNet' => $settings->showNet(),
            'smallSettlement' => $settings->taxScheme === Settings::SCHEME_SMALL,
            'taxRate' => 0,
            'taxText' => $decision->text,
            'taxType' => $decision->type,
            'taxRule' => ['id' => $decision->rule, 'objectName' => 'TaxRule'],
            'bookingCategory' => 'UNDERACHIEVEMENT',
            'refSrcInvoice' => (int)$record->sevdeskId,
        ];

        if ($record->contactId) {
            $creditNote['contact'] = ['id' => (int)$record->contactId, 'objectName' => 'Contact'];
        }

        $contactPersonId = $plugin->meta->contactPersonId();

        if ($contactPersonId !== null) {
            $creditNote['contactPerson'] = ['id' => $contactPersonId, 'objectName' => 'SevUser'];
        }

        $countryId = $plugin->meta->countryId($order->getBillingAddress()?->countryCode);

        if ($countryId !== null) {
            $creditNote['addressCountry'] = ['id' => $countryId, 'objectName' => 'StaticCountry'];
        }

        $created = $plugin->api->post('CreditNote/Factory/saveCreditNote', [
            'creditNote' => $creditNote,
            'creditNotePosSave' => [[
                'objectName' => 'CreditNotePos',
                'mapAll' => true,
                'quantity' => 1,
                'price' => $net,
                'name' => Craft::t('sevvies', 'Refund for order {reference}', [
                    'reference' => (string)($order->reference ?: $order->number),
                ]),
                'taxRate' => $rate,
                'unity' => ['id' => $settings->unityId, 'objectName' => 'Unity'],
            ]],
            'creditNotePosDelete' => null,
            'discountSave' => null,
            'discountDelete' => null,
        ], $order->id);

        return is_array($created) ? $created : [];
    }

    /**
     * The blended VAT rate the original invoice was issued at, so a partial
     * refund gives back the same proportion of tax that was charged.
     */
    private function invoiceTaxRate(InvoiceRecord $record): float
    {
        $net = (float)($record->sumNet ?? 0);
        $tax = (float)($record->sumTax ?? 0);

        if ($net <= 0 || $tax <= 0) {
            return 0.0;
        }

        $rate = ($tax / $net) * 100;

        foreach ([19.0, 7.0, 5.0, 16.0] as $known) {
            if (abs($rate - $known) < 0.15) {
                return $known;
            }
        }

        return Money::round($rate);
    }
}
