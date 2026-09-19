<?php

namespace App\Services\CutLuy\Exceptions;

use RuntimeException;

/**
 * Base class for every CutLuy API failure.
 *
 * CutLuy reports errors as { "error": code, "message": ... }; the machine
 * readable code is kept in $errorCode so callers can branch on it, while
 * getMessage() carries the human readable text.
 */
class CutLuyException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'unknown_error',
        public readonly int $status = 0,
    ) {
        parent::__construct($message, $status);
    }

    /**
     * Whether retrying the exact same request could plausibly succeed.
     */
    public function isRetryable(): bool
    {
        return $this->status === 0 || $this->status >= 500;
    }
}
