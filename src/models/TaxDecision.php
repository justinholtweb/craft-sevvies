<?php

namespace justinholtweb\sevvies\models;

use craft\base\Model;

/**
 * Which VAT rule an order falls under, and why.
 *
 * The reason is stored on the invoice row and shown in the CP: an accountant
 * asking "why is this one zero-rated?" deserves an answer that does not
 * require reading the source.
 */
class TaxDecision extends Model
{
    /** @var string sevDesk taxRule id (bookkeeping system 2.0). */
    public string $rule = '1';

    /** @var string sevDesk taxType (bookkeeping system 1.0). */
    public string $type = 'default';

    /** @var string Human label for the rule, in German — this is a German document. */
    public string $label = '';

    /** @var string Why this rule was chosen. */
    public string $reason = '';

    /** @var string Text printed on the document under the totals. */
    public string $text = '';

    /** @var float[]|null Tax rates sevDesk accepts for this rule; null means "depends on country". */
    public ?array $allowedRates = null;

    /** @var bool Positions must be zero-rated. */
    public bool $zeroRated = false;

    /** @var string|null Set when the order cannot be invoiced as it stands. */
    public ?string $error = null;

    public function isValid(): bool
    {
        return $this->error === null;
    }

    /**
     * Does sevDesk accept this rate under this rule?
     */
    public function allowsRate(float $rate): bool
    {
        if ($this->allowedRates === null) {
            return true;
        }

        foreach ($this->allowedRates as $allowed) {
            if (abs($allowed - $rate) < 0.001) {
                return true;
            }
        }

        return false;
    }
}
