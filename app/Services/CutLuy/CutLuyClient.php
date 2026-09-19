<?php

namespace App\Services\CutLuy;

use App\Services\CutLuy\Exceptions\AccountSuspendedException;
use App\Services\CutLuy\Exceptions\CutLuyException;
use App\Services\CutLuy\Exceptions\QuotaExceededException;
use App\Services\CutLuy\Exceptions\RateLimitedException;
use App\Services\CutLuy\Exceptions\UnauthorizedException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Thin client for the CutLuy payments API (https://cutluy.com).
 *
 * Every non-2xx response is translated into a typed exception so callers can
 * react to quota / auth / suspension / rate limiting explicitly. Nothing in
 * here retries a rate limited request: the Retry-After value is handed back on
 * the exception and it is the caller's job to decide what to do with it.
 */
class CutLuyClient
{
    public function __construct(
        protected string $baseUrl,
        protected ?string $apiKey,
        protected int $timeout = 15,
    ) {
    }

    /**
     * Create a payment and return the decoded CutLuy payment object.
     *
     * @param  float|string  $amount  USD, minimum 0.01.
     * @param  array<string, mixed>  $metadata  Returned unchanged by CutLuy.
     * @param  string|null  $idempotencyKey  Send one — it makes retries safe.
     * @return array<string, mixed>
     */
    public function createPayment(
        float|string $amount,
        ?string $referenceId = null,
        array $metadata = [],
        ?string $idempotencyKey = null,
    ): array {
        $amount = round((float) $amount, 2);

        if ($amount < 0.01) {
            throw new InvalidArgumentException('CutLuy payments must be at least 0.01 USD.');
        }

        $payload = array_filter([
            'amount' => $amount,
            'reference_id' => $referenceId,
            'metadata' => $metadata ?: null,
            'idempotency_key' => $idempotencyKey,
        ], fn ($value) => $value !== null);

        return $this->send('post', '/v1/payments', $payload);
    }

    /**
     * Read a single payment.
     *
     * Prefer the webhook for learning that a payment succeeded; this is for
     * manual reconciliation and for the "check now" button in the UI.
     *
     * @return array<string, mixed>
     */
    public function getPayment(string $id): array
    {
        return $this->send('get', '/v1/payments/'.urlencode($id));
    }

    /**
     * List payments.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function listPayments(array $query = []): array
    {
        return $this->send('get', '/v1/payments', $query);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function send(string $method, string $path, array $data = []): array
    {
        if (blank($this->apiKey)) {
            throw new UnauthorizedException(
                'No CutLuy API key configured. Set CUTLUY_API_KEY in your environment.',
                'unauthorized',
                401,
            );
        }

        try {
            $response = $this->request()->{$method}($this->baseUrl.$path, $data);
        } catch (ConnectionException $e) {
            // Network level failure — no HTTP status to branch on.
            throw new CutLuyException(
                'Could not reach CutLuy: '.$e->getMessage(),
                'connection_error',
                0,
            );
        }

        return $this->handle($response);
    }

    protected function request(): PendingRequest
    {
        return Http::withToken($this->apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            // Only transient network failures are retried, and with a pause
            // between attempts. HTTP errors (including 429) fall through to
            // handle() untouched.
            ->retry(3, 500, fn ($e) => $e instanceof ConnectionException, throw: false);
    }

    /**
     * @return array<string, mixed>
     */
    protected function handle(Response $response): array
    {
        if ($response->successful()) {
            return (array) $response->json();
        }

        $body = (array) ($response->json() ?? []);
        $code = is_string($body['error'] ?? null) ? $body['error'] : 'unknown_error';
        $message = is_string($body['message'] ?? null)
            ? $body['message']
            : 'CutLuy request failed with HTTP '.$response->status();

        throw match ($response->status()) {
            401 => new UnauthorizedException($message, $code, 401),
            402 => new QuotaExceededException($message, $code, 402),
            403 => new AccountSuspendedException($message, $code, 403),
            429 => new RateLimitedException($message, $code, 429, $this->retryAfter($response)),
            default => new CutLuyException($message, $code, $response->status()),
        };
    }

    /**
     * Retry-After is either a number of seconds or an HTTP date.
     */
    protected function retryAfter(Response $response): int
    {
        $header = $response->header('Retry-After');

        if ($header === '') {
            return 60;
        }

        if (is_numeric($header)) {
            return max(1, (int) $header);
        }

        $timestamp = strtotime($header);

        return $timestamp === false ? 60 : max(1, $timestamp - time());
    }
}
