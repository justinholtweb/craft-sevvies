<?php

namespace justinholtweb\sevvies\errors;

use RuntimeException;
use Throwable;

/**
 * A sevDesk API call that did not succeed.
 */
class ApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?string $responseBody = null,
        public readonly ?string $endpoint = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode ?? 0, $previous);
    }

    /**
     * Worth trying again? A 4xx is the merchant's data being wrong and will
     * fail identically forever; a 429 or 5xx is sevDesk having a moment.
     */
    public function isTransient(): bool
    {
        if ($this->statusCode === null) {
            // No response at all — a timeout or DNS failure.
            return true;
        }

        return $this->statusCode === 429 || $this->statusCode >= 500;
    }
}
