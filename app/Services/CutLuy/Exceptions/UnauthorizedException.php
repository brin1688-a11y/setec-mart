<?php

namespace App\Services\CutLuy\Exceptions;

/**
 * 401 unauthorized — the API key is missing, revoked or malformed.
 * Never retryable: the operator has to fix CUTLUY_API_KEY.
 */
class UnauthorizedException extends CutLuyException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
