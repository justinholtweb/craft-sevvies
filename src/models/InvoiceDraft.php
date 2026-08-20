<?php

namespace justinholtweb\sevvies\models;

use craft\base\Model;
use justinholtweb\sevvies\helpers\Money;

/**
 * Everything Sevvies intends to send for one order, plus the arithmetic that
 * says whether sending it would book the right money.
 *
 * A draft is produced identically by the CP preview, the console preview and
 * the real sync, so what you see before you commit is what sevDesk receives.
 */
class InvoiceDraft extends Model
{
    public int $orderId = 0;
    public string $orderReference = '';

    /** @var array The sevDesk `invoice` object. */
    public array $invoice = [];

    /** @var array[] The sevDesk `invoicePosSave` array. */
    public array $positions = [];

    /** @var array[] The sevDesk `discountSave` array. */
    public array $discounts = [];

    public ?TaxDecision $decision = null;

    /** @var float What Commerce charged the customer. */
    public float $expectedGross = 0.0;

    /** @var float What these positions and discounts add up to. */
    public float $computedGross = 0.0;

    public float $computedNet = 0.0;
    public float $computedTax = 0.0;

    public string $currency = 'EUR';

    /**
     * @var string[] Reasons this draft must not be sent.
     *
     * Not `$errors` — that is Yii's own validation surface, and shadowing it
     * would break `hasErrors()` in ways that only show up under validation.
     */
    public array $blockers = [];

    /** @var string[] Things worth knowing that do not block sending. */
    public array $warnings = [];

    /**
     * The full request body for Invoice/Factory/saveInvoice.
     *
     * The trailing four keys are sent in this order and always present —
     * sevDesk's factory endpoint documents that requirement explicitly.
     */
    public function payload(): array
    {
        return [
            'invoice' => $this->invoice,
            'invoicePosSave' => array_map(fn(array $position): array => $this->wireFormat($position), $this->positions),
            'invoicePosDelete' => null,
            'discountSave' => $this->discounts ?: null,
            'discountDelete' => null,
            'takeDefaultAddress' => false,
        ];
    }

    /**
     * Positions carry `_net`, `_tax` and `_gross` so the draft can do its own
     * arithmetic. Those are Sevvies' bookkeeping, not sevDesk's — strip them
     * before anything goes on the wire.
     */
    private function wireFormat(array $position): array
    {
        foreach (array_keys($position) as $key) {
            if (str_starts_with((string)$key, '_')) {
                unset($position[$key]);
            }
        }

        return $position;
    }

    public function isSendable(): bool
    {
        return $this->blockers === [];
    }

    /**
     * Does the arithmetic agree with Commerce before anything is sent?
     */
    public function balances(): bool
    {
        return Money::same($this->computedGross, $this->expectedGross);
    }

    public function difference(): float
    {
        return Money::round($this->computedGross - $this->expectedGross);
    }

    public function hash(): string
    {
        return hash('sha256', json_encode($this->payload(), JSON_THROW_ON_ERROR));
    }
}
