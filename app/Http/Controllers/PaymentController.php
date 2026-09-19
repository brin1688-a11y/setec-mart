<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessCutLuyWebhook;
use App\Models\Order;
use App\Models\Payment;
use App\Services\CutLuy\CutLuyClient;
use App\Services\CutLuy\Exceptions\CutLuyException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class PaymentController extends Controller
{
    public function __construct(protected CutLuyClient $cutluy) {}

    /**
     * The KHQR pay page: renders the QR CutLuy gave us for this order.
     */
    public function khqr(Order $order)
    {
        $payment = $this->authorizedPayment($order);

        if ($payment->isPaid()) {
            return redirect()->route('orders.show', $order)
                ->with('success', 'Payment received — thank you!');
        }

        if ($payment->method !== Payment::METHOD_KHQR) {
            return redirect()->route('orders.show', $order);
        }

        // "Pay now" on an order whose few minutes ran out should just work, so
        // fetch a fresh QR rather than showing a dead one. renewQr() checks
        // first whether the payment quietly landed, and throttles itself.
        if (($payment->hasExpired() || blank($payment->qr_string)) && $payment->canRenewQr()) {
            // With no QR at all there is nothing to fall back on — editing the
            // order clears it so the next code is drawn for the new total — so
            // do not let the refresh throttle leave the page empty-handed.
            $this->renewQr($payment, force: blank($payment->qr_string));
            $payment->refresh();

            if ($payment->isPaid()) {
                return redirect()->route('orders.show', $order)
                    ->with('success', 'Payment received — thank you!');
            }
        }

        if (blank($payment->qr_string)) {
            return redirect()->route('orders.show', $order)
                ->with('error', 'We could not start a KHQR payment for this order. Please try again shortly.');
        }

        return view('payments.khqr', compact('order', 'payment'));
    }

    /**
     * "Get a new QR code" — the customer asking for another few minutes.
     */
    public function renew(Order $order)
    {
        $payment = $this->authorizedPayment($order);

        if ($payment->isPaid()) {
            return redirect()->route('orders.show', $order)
                ->with('success', 'Payment received — thank you!');
        }

        if (! $payment->canRenewQr()) {
            return redirect()->route('orders.show', $order)->with(
                'error',
                $payment->renewals >= Payment::MAX_RENEWALS
                    ? 'This order has been given too many QR codes. Please place it again.'
                    : 'This order can no longer be paid. Please place it again.'
            );
        }

        if (! $this->renewQr($payment, force: true)) {
            return back()->with('error', 'We could not reach the payment provider. Please try again shortly.');
        }

        return $payment->fresh()->isPaid()
            ? redirect()->route('orders.show', $order)->with('success', 'Payment received — thank you!')
            : redirect()->route('payments.khqr', $order)->with('success', 'Here is a fresh QR code.');
    }

    /**
     * Replace a lapsed QR with a new one from CutLuy.
     *
     * The old payment is checked first: a QR can expire in the seconds between
     * the customer paying and the webhook landing, and issuing a second QR for
     * money we already have would invite paying twice.
     *
     * The order keeps its stock throughout — this swaps the QR on the existing
     * payment row rather than creating a second one.
     *
     * Returns false only when CutLuy could not be reached.
     */
    protected function renewQr(Payment $payment, bool $force = false): bool
    {
        $throttleKey = 'cutluy-renew:'.$payment->order_id;

        // A page refresh must not mint a QR each time. Cache::add is atomic,
        // so concurrent tabs share one renewal.
        if (! $force && ! Cache::add($throttleKey, true, now()->addSeconds(20))) {
            return true;
        }

        if (RateLimiter::tooManyAttempts($throttleKey, maxAttempts: 6)) {
            return true;
        }

        RateLimiter::hit($throttleKey, decaySeconds: 300);

        // Did it actually settle while the clock ran out? Ask, but act only on
        // good news: running the full reconcile here would see "expired",
        // cancel the order and hand its stock back, leaving nothing to renew.
        $remote = $this->fetchRemote($payment);

        if ($remote === null) {
            return false;
        }

        if (($remote['status'] ?? null) === 'paid') {
            ProcessCutLuyWebhook::dispatchSync('payment.completed', $remote);

            return true;
        }

        if (! $payment->fresh()->canRenewQr()) {
            return true;
        }

        $attempt = $payment->renewals + 1;

        try {
            $fresh = $this->cutluy->createPayment(
                amount: $payment->order->total,
                referenceId: 'order_'.$payment->order_id,
                metadata: [
                    'order_id' => (string) $payment->order_id,
                    'user_id' => (string) $payment->order->user_id,
                    'renewal' => (string) $attempt,
                ],
                // CutLuy replays the original payment for a key it has already
                // seen, so a renewal has to carry its own.
                idempotencyKey: 'order_'.$payment->order_id.'_r'.$attempt,
            );
        } catch (CutLuyException $e) {
            Log::error('Could not renew a CutLuy QR: '.$e->getMessage(), [
                'order_id' => $payment->order_id,
                'error' => $e->errorCode,
                'status' => $e->status,
            ]);

            return false;
        }

        Log::info('Issued a fresh KHQR code.', [
            'order_id' => $payment->order_id,
            'previous_payment_id' => $payment->cutluy_payment_id,
            'payment_id' => $fresh['id'] ?? null,
            'renewal' => $attempt,
        ]);

        $payment->update([
            'cutluy_payment_id' => $fresh['id'] ?? $payment->cutluy_payment_id,
            'cutluy_status' => $fresh['status'] ?? 'pending',
            'checkout_url' => $fresh['checkout_url'] ?? null,
            'qr_string' => $fresh['qr_string'] ?? null,
            'amount' => $fresh['amount'] ?? $payment->amount,
            'currency' => $fresh['currency'] ?? $payment->currency,
            'expires_at' => $fresh['expires_at'] ?? null,
            'renewals' => $attempt,
        ]);

        return true;
    }

    /**
     * Polled by the pay page every few seconds.
     *
     * The webhook is still the primary way a payment settles. But a webhook
     * can be missed — the endpoint may be unreachable (local development
     * behind no tunnel), or the queue may be backed up — and the customer
     * should not be left staring at a QR they have already paid. So while the
     * payment is unsettled this also asks CutLuy directly, at most once every
     * few seconds per payment.
     */
    public function status(Order $order)
    {
        $payment = $this->authorizedPayment($order);

        if ($this->isUnsettled($payment)) {
            $this->reconcile($payment);
            $payment->refresh();
        }

        return response()->json([
            'status' => $payment->status,
            'provider_status' => $payment->cutluy_status,
            'paid' => $payment->isPaid(),
            'expires_at' => $payment->expires_at?->toIso8601String(),
            // The page asks on every tick rather than trusting what was true
            // when it was rendered: an order can be released while it sits open.
            'can_renew' => $payment->canRenewQr(),
            'redirect' => $payment->isPaid() ? route('orders.show', $order) : null,
        ]);
    }

    /**
     * "Check now" — the customer asking us to look right away.
     */
    public function refresh(Request $request, Order $order)
    {
        $payment = $this->authorizedPayment($order);

        if ($payment->isPaid()) {
            return redirect()->route('orders.show', $order)
                ->with('success', 'Payment received — thank you!');
        }

        if (! $this->isUnsettled($payment)) {
            return redirect()->route('payments.khqr', $order);
        }

        $throttleKey = 'cutluy-refresh:'.$order->id;

        if (RateLimiter::tooManyAttempts($throttleKey, maxAttempts: 6)) {
            return back()->with('error', 'Please wait a moment before checking again.');
        }

        RateLimiter::hit($throttleKey, decaySeconds: 60);

        // force: the customer pressed the button, so skip the poll throttle.
        if (! $this->reconcile($payment, force: true)) {
            return back()->with('error', 'We could not reach the payment provider. Please try again shortly.');
        }

        return $payment->fresh()->isPaid()
            ? redirect()->route('orders.show', $order)->with('success', 'Payment received — thank you!')
            : back()->with('error', 'No payment received yet. If you have just paid, give it a few seconds.');
    }

    /**
     * Ask CutLuy what it thinks of this payment and apply the answer.
     *
     * Throttled per payment so the 3-second page poll cannot turn into 3
     * API calls a second; reads are capped at 600/minute per API key.
     *
     * Returns false only when CutLuy could not be reached.
     */
    protected function reconcile(Payment $payment, bool $force = false): bool
    {
        $throttle = 'cutluy-poll:'.$payment->cutluy_payment_id;

        // Cache::add is atomic — it succeeds for the first caller in the
        // window and fails for the rest, so concurrent tabs share one call.
        if (! $force && ! Cache::add($throttle, true, now()->addSeconds(5))) {
            return true;
        }

        $remote = $this->fetchRemote($payment);

        if ($remote === null) {
            return false;
        }

        $remoteStatus = $remote['status'] ?? $payment->cutluy_status;

        $payment->update(array_filter([
            'cutluy_status' => $remoteStatus,
            'expires_at' => $remote['expires_at'] ?? null,
        ], fn ($value) => $value !== null));

        // The same idempotent job the webhook uses, so there is still exactly
        // one piece of code that can settle an order. Run inline rather than
        // queued: the customer is waiting on this response, and it must not
        // depend on a queue worker being up.
        $event = match ($remoteStatus) {
            'paid' => 'payment.completed',
            'expired' => 'payment.expired',
            'failed' => 'payment.failed',
            default => null,
        };

        if ($event) {
            ProcessCutLuyWebhook::dispatchSync($event, $remote);
        }

        return true;
    }

    /**
     * Ask CutLuy what it currently knows about this payment.
     *
     * Returns null when the provider could not be reached — which is not the
     * same as "not paid", so callers must not treat it as a verdict.
     *
     * @return array<string, mixed>|null
     */
    protected function fetchRemote(Payment $payment): ?array
    {
        try {
            return $this->cutluy->getPayment($payment->cutluy_payment_id);
        } catch (CutLuyException $e) {
            Log::warning('Could not read a CutLuy payment: '.$e->getMessage(), [
                'order_id' => $payment->order_id,
                'error' => $e->errorCode,
            ]);

            return null;
        }
    }

    /**
     * A KHQR payment that still has somewhere to go.
     */
    protected function isUnsettled(Payment $payment): bool
    {
        return $payment->method === Payment::METHOD_KHQR
            && $payment->status === Payment::STATUS_PENDING
            && filled($payment->cutluy_payment_id);
    }

    protected function authorizedPayment(Order $order): Payment
    {
        if ($order->user_id !== Auth::id()) {
            abort(403);
        }

        $payment = $order->payment;

        if (! $payment) {
            abort(404);
        }

        return $payment;
    }
}
