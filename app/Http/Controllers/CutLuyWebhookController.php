<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessCutLuyWebhook;
use App\Services\CutLuy\Exceptions\InvalidSignatureException;
use App\Services\CutLuy\WebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives CutLuy webhook deliveries.
 *
 * Contract with CutLuy: verify, queue, answer 2xx. A non-2xx answer is retried
 * with exponential backoff up to 8 times, so this method must not do anything
 * that could be slow or flaky.
 */
class CutLuyWebhookController extends Controller
{
    public function __invoke(Request $request)
    {
        // The exact bytes CutLuy signed. getContent() hands back the untouched
        // request body — do NOT use $request->all() / ->json() here, since
        // decoding and re-encoding the JSON changes the bytes and the HMAC
        // would never match again.
        $payload = $request->getContent();

        try {
            WebhookSignature::verify(
                rawBody: $payload,
                header: $request->header('X-CutLuy-Signature'),
                secret: config('services.cutluy.webhook_secret'),
                tolerance: (int) config('services.cutluy.webhook_tolerance', 300),
            );
        } catch (InvalidSignatureException $e) {
            Log::warning('Rejected a CutLuy webhook delivery.', [
                'reason' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);

            // 400, not 401: CutLuy should not keep retrying something we will
            // never accept.
            return response()->json(['error' => 'invalid_signature'], 400);
        }

        $event = (string) $request->header('X-CutLuy-Event');
        $data = json_decode($payload, true);

        if ($event === '' || ! is_array($data)) {
            Log::warning('CutLuy webhook had a valid signature but an unusable body.');

            return response()->json(['error' => 'invalid_payload'], 400);
        }

        // All the real work happens on the queue.
        ProcessCutLuyWebhook::dispatch($event, $data);

        return response()->json(['received' => true], 202);
    }
}
