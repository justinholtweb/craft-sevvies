<?php

namespace justinholtweb\sevvies\errors;

use RuntimeException;

/**
 * sevDesk booked an amount that is not the amount Commerce charged.
 *
 * This is never retried and never ignored: a bookkeeping document that
 * disagrees with the money taken is worse than no document at all.
 */
class ReconciliationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly float $expected = 0.0,
        public readonly float $actual = 0.0,
        public readonly ?int $sevdeskId = null,
    ) {
        parent::__construct($message);
    }
}
