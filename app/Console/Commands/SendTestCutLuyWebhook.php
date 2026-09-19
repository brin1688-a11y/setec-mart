<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Sends a correctly signed webhook delivery at your own app, so the CutLuy
 * flow can be exercised locally without a public tunnel.
 *
 * Signs exactly the way CutLuy does — hex HMAC-SHA256 over "{t}.{rawBody}" —
 * so a delivery that this command gets through is one the real thing would
 * get through too.
 */
class SendTestCutLuyWebhook extends Command
{
    protected $signature = 'cutluy:test-webhook
        {order : The order id to send an event for}
        {--event=payment.completed : payment.completed, payment.scanned, payment.expired or payment.failed}
        {--url= : Override the endpoint (defaults to APP_URL/webhooks/cutluy)}
        {--stale : Sign with a 10 minute old timestamp, to check the replay window}
        {--bad-secret : Sign with the wrong secret, to check signature rejection}';

    protected $description = 'Send a signed test CutLuy webhook at this application';

    public function handle(): int
    {
        $order = Order::with('payment')->find($this->argument('order'));

        if (! $order || ! $order->payment) {
            $this->error('No order (or no payment on it) with id '.$this->argument('order'));

            return self::FAILURE;
        }

        if (blank($order->payment->cutluy_payment_id)) {
            $this->error('Order '.$order->id.' has no CutLuy payment id — place a KHQR order first.');

            return self::FAILURE;
        }

        $secret = config('services.cutluy.webhook_secret');

        if (blank($secret)) {
            $this->error('CUTLUY_WEBHOOK_SECRET is not set.');

            return self::FAILURE;
        }

        if ($this->option('bad-secret')) {
            $secret = 'whsec_deliberately_wrong';
        }

        $event = $this->option('event');

        $body = json_encode([
            'id' => $order->payment->cutluy_payment_id,
            'status' => match ($event) {
                'payment.completed' => 'paid',
                'payment.scanned' => 'scanned',
                'payment.expired' => 'expired',
                default => 'failed',
            },
            'amount' => (string) $order->payment->amount,
            'currency' => $order->payment->currency ?? 'USD',
            'reference_id' => 'order_'.$order->id,
        ]);

        $timestamp = $this->option('stale') ? time() - 600 : time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        $url = $this->option('url') ?: rtrim(config('app.url'), '/').'/webhooks/cutluy';

        $this->line('POST '.$url);
        $this->line('  event: '.$event);
        $this->line('  body:  '.$body);

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-CutLuy-Event' => $event,
            'X-CutLuy-Signature' => "t={$timestamp},v1={$signature}",
        ])->withBody($body, 'application/json')->post($url);

        $this->newLine();
        $this->line('  → HTTP '.$response->status().' '.$response->body());
        $this->newLine();

        if ($response->successful()) {
            $this->info('Accepted. The work is on the queue — make sure a worker is running:');
            $this->line('  php artisan queue:work');

            return self::SUCCESS;
        }

        $this->warn('Rejected. That is the right answer for --stale and --bad-secret.');

        return self::SUCCESS;
    }
}
