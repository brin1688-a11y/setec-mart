<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies a verified CutLuy webhook to our own records.
 *
 * The controller answers CutLuy immediately and queues this, so a slow
 * database or a hiccup while updating an order never turns into a non-2xx
 * response (which CutLuy would retry up to 8 times).
 *
 * Everything here keys off the CutLuy payment id and is safe to run twice:
 * the same delivery arriving again finds the payment already settled and
 * returns without touching anything.
 */
class ProcessCutLuyWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 120, 300];

    /**
     * @param  array<string, mixed>  $payload  The decoded webhook body.
     */
    public function __construct(
        public readonly string $event,
        public readonly array $payload,
    ) {
    }

    public function handle(): void
    {
        $paymentId = $this->payload['id'] ?? null;

        if (! is_string($paymentId) || $paymentId === '') {
            Log::warning('CutLuy webhook without a payment id.', ['event' => $this->event]);

            return;
        }

        match ($this->event) {
            'payment.completed' => $this->complete($paymentId),
            'payment.scanned' => $this->touchStatus($paymentId, 'scanned'),
            'payment.expired' => $this->release($paymentId, 'expired', Payment::STATUS_EXPIRED),
            'payment.failed' => $this->release($paymentId, 'failed', Payment::STATUS_FAILED),
            default => Log::info('Unhandled CutLuy webhook event.', [
                'event' => $this->event,
                'payment_id' => $paymentId,
            ]),
        };
    }

    /**
     * payment.completed — the only event that fulfils an order.
     */
    protected function complete(string $paymentId): void
    {
        DB::transaction(function () use ($paymentId) {
            $payment = $this->lockPayment($paymentId);

            if (! $payment) {
                return;
            }

            // Idempotency: a redelivered payment.completed lands here with the
            // payment already marked Paid and stops.
            if ($payment->isPaid()) {
                return;
            }

            if (! $this->amountMatches($payment)) {
                // Never fulfil an order for the wrong amount. Left Pending on
                // purpose so a human looks at it.
                Log::error('CutLuy webhook amount did not match the order.', [
                    'payment_id' => $paymentId,
                    'order_id' => $payment->order_id,
                    'expected' => $payment->amount,
                    'received' => $this->payload['amount'] ?? null,
                ]);

                return;
            }

            $payment->forceFill([
                'status' => Payment::STATUS_PAID,
                'cutluy_status' => 'paid',
                'paid_at' => now(),
                'last_event_at' => now(),
            ])->save();

            $order = Order::whereKey($payment->order_id)->lockForUpdate()->first();

            // Only move an untouched order forward; if an admin already
            // advanced or cancelled it, leave their decision alone.
            if ($order && $order->status === 'Pending') {
                $order->update(['status' => 'Confirmed']);
            }

            Log::info('CutLuy payment completed.', [
                'payment_id' => $paymentId,
                'order_id' => $payment->order_id,
            ]);
        });
    }

    /**
     * payment.scanned — the customer opened the QR in their banking app but has
     * NOT paid. Recorded for the UI, nothing is fulfilled.
     */
    protected function touchStatus(string $paymentId, string $cutluyStatus): void
    {
        DB::transaction(function () use ($paymentId, $cutluyStatus) {
            $payment = $this->lockPayment($paymentId);

            if (! $payment || $payment->isPaid()) {
                return;
            }

            $payment->forceFill([
                'cutluy_status' => $cutluyStatus,
                'last_event_at' => now(),
            ])->save();
        });
    }

    /**
     * payment.expired / payment.failed — the money never moved, so cancel the
     * order and put the reserved stock back.
     */
    protected function release(string $paymentId, string $cutluyStatus, string $status): void
    {
        DB::transaction(function () use ($paymentId, $cutluyStatus, $status) {
            $payment = $this->lockPayment($paymentId);

            if (! $payment) {
                return;
            }

            // A payment that already settled wins over a late expiry notice.
            if ($payment->isPaid() || $payment->status === $status) {
                return;
            }

            $payment->forceFill([
                'status' => $status,
                'cutluy_status' => $cutluyStatus,
                'last_event_at' => now(),
            ])->save();

            $order = Order::with('items')->whereKey($payment->order_id)->lockForUpdate()->first();

            // The status guard is what keeps the stock restore from running
            // twice if this event is redelivered.
            if ($order && $order->status === 'Pending') {
                $order->restoreStock();
                $order->update(['status' => 'Cancelled']);
            }

            Log::info('CutLuy payment released.', [
                'payment_id' => $paymentId,
                'order_id' => $payment->order_id,
                'status' => $status,
            ]);
        });
    }

    protected function lockPayment(string $paymentId): ?Payment
    {
        $payment = Payment::where('cutluy_payment_id', $paymentId)->lockForUpdate()->first();

        if (! $payment) {
            // Not ours (another app sharing the key, or a test delivery).
            // Swallowed rather than thrown so the job is not retried forever.
            Log::warning('CutLuy webhook for an unknown payment.', [
                'event' => $this->event,
                'payment_id' => $paymentId,
            ]);
        }

        return $payment;
    }

    /**
     * Guard against a webhook claiming a different amount than we charged.
     */
    protected function amountMatches(Payment $payment): bool
    {
        $received = $this->payload['amount'] ?? null;

        if ($received === null || $payment->amount === null) {
            return true;
        }

        return abs((float) $received - (float) $payment->amount) < 0.005;
    }
}
