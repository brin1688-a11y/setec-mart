<?php

namespace App\Services\CutLuy\Exceptions;

/**
 * 429 rate_limited — creates are capped at 60/minute per API key, reads at
 * 600/minute. $retryAfter is the number of seconds CutLuy asked us to wait;
 * callers must honour it instead of retrying in a tight loop.
 */
class RateLimitedException extends CutLuyException
{
    public function __construct(
        string $message,
        string $errorCode,
        int $status,
        public readonly int $retryAfter = 60,
    ) {
        parent::__construct($message, $errorCode, $status);
    }

    public function isRetryable(): bool
    {
        return true;
    }
}
