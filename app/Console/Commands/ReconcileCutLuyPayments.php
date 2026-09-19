<?php

namespace App\Console\Commands;

use App\Jobs\ProcessCutLuyWebhook;
use App\Models\Payment;
use App\Services\CutLuy\CutLuyClient;
use App\Services\CutLuy\Exceptions\CutLuyException;
use App\Services\CutLuy\Exceptions\RateLimitedException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Settles KHQR payments that never got a webhook.
 *
 * A webhook can be missed for ordinary reasons — the endpoint was down, the
 * tunnel was closed, the queue was stopped — and when that happens an order
 * sits Pending forever while holding its stock. This asks CutLuy directly for
 * anything still unsettled and applies the answer.
 *
 * Only KHQR is touched. Cash-on-delivery orders are legitimately Pending until
 * a human confirms them, so they are never auto-cancelled.
 */
class ReconcileCutLuyPayments extends Command
{
    protected $signature = 'cutluy:reconcile
        {--limit=50 : Most payments to check in one run}
        {--minutes=10 : Only look at payments older than this, to leave live checkouts alone}
        {--days=7 : Ignore payments older than this}';

    protected $description = 'Settle KHQR payments whose webhook never arrived, releasing stock for expired ones';

    public function handle(CutLuyClient $cutluy): int
    {
        $payments = Payment::query()
            ->where('method', Payment::METHOD_KHQR)
            ->where('status', Payment::STATUS_PENDING)
            ->whereNotNull('cutluy_payment_id')
            // Leave a checkout that is still on screen alone; the page polls
            // for itself and the QR may not have expired yet.
            ->where('created_at', '<', now()->subMinutes((int) $this->option('minutes')))
            ->where('created_at', '>', now()->subDays((int) $this->option('days')))
            ->orderBy('created_at')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($payments->isEmpty()) {
            $this->info('Nothing to reconcile.');

            return self::SUCCESS;
        }

        $this->line('Checking ' . $payments->count() . ' unsettled KHQR ' . str('payment')->plural($payments->count()) . '...');

        $settled = 0;
        $released = 0;
        $stillPending = 0;

        foreach ($payments as $payment) {
            try {
                $remote = $cutluy->getPayment($payment->cutluy_payment_id);
            } catch (RateLimitedException $e) {
                // Reads are capped at 600/minute. Stop rather than hammer;
                // the next scheduled run picks up where this left off.
                $this->warn('Rate limited — stopping. Retry after ' . $e->retryAfter . 's.');
                break;
            } catch (CutLuyException $e) {
                $this->warn('Order ' . $payment->order_id . ': ' . $e->getMessage());
                Log::warning('Reconcile could not read a CutLuy payment: ' . $e->getMessage(), [
                    'order_id' => $payment->order_id,
                ]);

                continue;
            }

            $status = $remote['status'] ?? null;

            $event = match ($status) {
                'paid' => 'payment.completed',
                'expired' => 'payment.expired',
                'failed' => 'payment.failed',
                default => null,
            };

            $payment->update(['cutluy_status' => $status ?? $payment->cutluy_status]);

            if (! $event) {
                $stillPending++;
                continue;
            }

            // The same idempotent job the webhook uses — run inline so this
            // command does not depend on a worker being up.
            ProcessCutLuyWebhook::dispatchSync($event, $remote);

            if ($status === 'paid') {
                $settled++;
                $this->line('  order ' . $payment->order_id . ' → paid, confirmed');
            } else {
                $released++;
                $this->line('  order ' . $payment->order_id . ' → ' . $status . ', cancelled and stock returned');
            }
        }

        $this->newLine();
        $this->info("Confirmed: {$settled}  ·  Released: {$released}  ·  Still awaiting payment: {$stillPending}");

        return self::SUCCESS;
    }
}
