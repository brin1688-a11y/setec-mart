<?php

namespace App\Services\CutLuy;

use App\Services\CutLuy\Exceptions\InvalidSignatureException;

/**
 * Verifies the X-CutLuy-Signature header.
 *
 * The header looks like `t=<unix>,v1=<hex hmac>` and v1 is a hex encoded
 * HMAC-SHA256, keyed with the endpoint secret, over the string "{t}.{rawBody}".
 *
 * $rawBody MUST be the exact bytes CutLuy sent. Decoding the JSON and
 * re-encoding it changes key order, spacing and number formatting, and the
 * signature will never match again.
 */
class WebhookSignature
{
    /**
     * @throws InvalidSignatureException
     */
    public static function verify(
        string $rawBody,
        ?string $header,
        ?string $secret,
        int $tolerance = 300,
    ): void {
        if (blank($secret)) {
            throw new InvalidSignatureException(
                'No CutLuy webhook secret configured. Set CUTLUY_WEBHOOK_SECRET.'
            );
        }

        if (blank($header)) {
            throw new InvalidSignatureException('Missing X-CutLuy-Signature header.');
        }

        $parts = static::parse($header);

        $timestamp = $parts['t'] ?? null;
        $signature = $parts['v1'] ?? null;

        if ($timestamp === null || ! ctype_digit($timestamp) || $signature === null) {
            throw new InvalidSignatureException('Malformed X-CutLuy-Signature header.');
        }

        // Replay window. Deliveries signed too long ago are refused even if the
        // signature itself is perfectly valid; a clock that is too far ahead is
        // refused for the same reason.
        if (abs(time() - (int) $timestamp) > $tolerance) {
            throw new InvalidSignatureException('Webhook timestamp outside the tolerance window.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        // hash_equals is PHP's constant time comparison — it does not leak how
        // many leading characters of a forged signature were correct.
        if (! hash_equals($expected, $signature)) {
            throw new InvalidSignatureException('Webhook signature mismatch.');
        }
    }

    /**
     * Turn "t=123,v1=abc" into ['t' => '123', 'v1' => 'abc'].
     *
     * @return array<string, string>
     */
    protected static function parse(string $header): array
    {
        $parts = [];

        foreach (explode(',', $header) as $segment) {
            $pair = explode('=', trim($segment), 2);

            if (count($pair) === 2) {
                $parts[trim($pair[0])] = trim($pair[1]);
            }
        }

        return $parts;
    }
}
