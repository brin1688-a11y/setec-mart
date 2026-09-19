<?php

namespace App\Services\CutLuy\Exceptions;

use RuntimeException;

/**
 * A webhook delivery failed verification: bad header, stale timestamp, or a
 * signature that does not match. Always treated as "not from CutLuy".
 */
class InvalidSignatureException extends RuntimeException
{
}
