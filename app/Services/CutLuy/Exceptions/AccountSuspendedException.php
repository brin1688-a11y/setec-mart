<?php

namespace App\Services\CutLuy\Exceptions;

/**
 * 403 account_suspended — the CutLuy account cannot accept payments.
 * Not retryable.
 */
class AccountSuspendedException extends CutLuyException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
