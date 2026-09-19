<?php

namespace App\Services\CutLuy\Exceptions;

/**
 * 402 quota_exceeded — the CutLuy account is out of quota.
 * Not retryable until someone tops the account up.
 */
class QuotaExceededException extends CutLuyException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
