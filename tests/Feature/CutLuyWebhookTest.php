<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CutLuyWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected string $secret = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.cutluy.webhook_secret' => $this->secret,
            'services.cutluy.webhook_tolerance' => 300,
        ]);
    }

    /**
     * Build an order with one item, stock already decremented, awaiting KHQR.
     */
    protected function makeOrder(string $cutluyId = 'PUETcMUOKStjZsCb', string $amount = '1.50'): Order
    {
        $user = User::factory()->create();

        $category = Category::create(['name' => 'Produce']);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Mango',
            'description' => 'Sweet',
            'price' => 1.50,
            'stock' => 9, // one already reserved by the order below
            'status' => 'active',
        ]);

        $order = Order::create([
            'user_id' => $user->id,
            'name' => 'Sok',
            'phone' => '012345678',
            'address' => 'Phnom Penh',
            'status' => 'Pending',
            'total' => $amount,
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'price' => $product->price,
            'quantity' => 1,
        ]);

        $order->payment()->create([
            'method' => Payment::METHOD_KHQR,
            'status' => Payment::STATUS_PENDING,
            'cutluy_payment_id' => $cutluyId,
            'cutluy_status' => 'pending',
            'amount' => $amount,
            'currency' => 'USD',
        ]);

        return $order->fresh();
    }

    /**
     * Post a delivery exactly the way CutLuy would: raw JSON body plus the
     * signature headers.
     */
    protected function deliver(
        string $event,
        array $payload,
        ?string $secret = null,
        ?int $timestamp = null,
    ) {
        $body = json_encode($payload);
        $timestamp ??= time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret ?? $this->secret);

        return $this->call(
            method: 'POST',
            uri: '/webhooks/cutluy',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CUTLUY_EVENT' => $event,
                'HTTP_X_CUTLUY_SIGNATURE' => "t={$timestamp},v1={$signature}",
            ],
            content: $body,
        );
    }

    public function test_a_completed_payment_confirms_the_order(): void
    {
        $order = $this->makeOrder();

        $response = $this->deliver('payment.completed', [
            'id' => 'PUETcMUOKStjZsCb',
            'status' => 'paid',
            'amount' => '1.50',
            'currency' => 'USD',
        ]);

        $response->assertSuccessful();

        $this->assertSame('Confirmed', $order->fresh()->status);
        $this->assertSame(Payment::STATUS_PAID, $order->fresh()->payment->status);
        $this->assertNotNull($order->fresh()->payment->paid_at);
    }

    public function test_a_redelivered_event_changes_nothing(): void
    {
        $order = $this->makeOrder();

        $payload = [
            'id' => 'PUETcMUOKStjZsCb',
            'status' => 'paid',
            'amount' => '1.50',
        ];

        $this->deliver('payment.completed', $payload)->assertSuccessful();

        $paidAt = $order->fresh()->payment->paid_at;

        // An admin moves the order on before the duplicate arrives.
        $order->update(['status' => 'Preparing']);

        $this->deliver('payment.completed', $payload)->assertSuccessful();

        $this->assertSame('Preparing', $order->fresh()->status);
        $this->assertEquals($paidAt, $order->fresh()->payment->paid_at);
    }

    public function test_a_scanned_event_does_not_fulfil_the_order(): void
    {
        $order = $this->makeOrder();

        $this->deliver('payment.scanned', [
            'id' => 'PUETcMUOKStjZsCb',
            'status' => 'scanned',
            'amount' => '1.50',
        ])->assertSuccessful();

        $this->assertSame('Pending', $order->fresh()->status);
        $this->assertSame(Payment::STATUS_PENDING, $order->fresh()->payment->status);
        $this->assertSame('scanned', $order->fresh()->payment->cutluy_status);
    }

    public function test_an_expired_payment_cancels_the_order_and_restores_stock(): void
    {
        $order = $this->makeOrder();
        $product = $order->items->first()->product;

        $this->deliver('payment.expired', [
            'id' => 'PUETcMUOKStjZsCb',
            'status' => 'expired',
            'amount' => '1.50',
        ])->assertSuccessful();

        $this->assertSame('Cancelled', $order->fresh()->status);
        $this->assertSame(Payment::STATUS_EXPIRED, $order->fresh()->payment->status);
        $this->assertSame(10, $product->fresh()->stock);
    }

    public function test_a_redelivered_expiry_does_not_restore_stock_twice(): void
    {
        $order = $this->makeOrder();
        $product = $order->items->first()->product;

        $payload = ['id' => 'PUETcMUOKStjZsCb', 'status' => 'expired', 'amount' => '1.50'];

        $this->deliver('payment.expired', $payload)->assertSuccessful();
        $this->deliver('payment.expired', $payload)->assertSuccessful();

        $this->assertSame(10, $product->fresh()->stock);
    }

    public function test_a_wrong_signature_is_rejected(): void
    {
        $order = $this->makeOrder();

        $this->deliver(
            'payment.completed',
            ['id' => 'PUETcMUOKStjZsCb', 'status' => 'paid', 'amount' => '1.50'],
            secret: 'whsec_the_wrong_secret',
        )->assertStatus(400);

        $this->assertSame('Pending', $order->fresh()->status);
    }

    public function test_a_missing_signature_header_is_rejected(): void
    {
        $this->makeOrder();

        $this->call(
            method: 'POST',
            uri: '/webhooks/cutluy',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CUTLUY_EVENT' => 'payment.completed',
            ],
            content: json_encode(['id' => 'PUETcMUOKStjZsCb', 'status' => 'paid']),
        )->assertStatus(400);
    }

    public function test_a_stale_delivery_is_rejected(): void
    {
        $order = $this->makeOrder();

        $this->deliver(
            'payment.completed',
            ['id' => 'PUETcMUOKStjZsCb', 'status' => 'paid', 'amount' => '1.50'],
            timestamp: time() - 900,
        )->assertStatus(400);

        $this->assertSame('Pending', $order->fresh()->status);
    }

    public function test_a_signature_over_reserialised_json_is_rejected(): void
    {
        $order = $this->makeOrder();

        // What a handler that signed the parsed-and-re-encoded body would send:
        // same data, different bytes. It must not verify.
        $sentBody = '{"id":"PUETcMUOKStjZsCb", "status":"paid", "amount":"1.50"}';
        $reserialised = json_encode(json_decode($sentBody, true));
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $reserialised, $this->secret);

        $this->call(
            method: 'POST',
            uri: '/webhooks/cutluy',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CUTLUY_EVENT' => 'payment.completed',
                'HTTP_X_CUTLUY_SIGNATURE' => "t={$timestamp},v1={$signature}",
            ],
            content: $sentBody,
        )->assertStatus(400);

        $this->assertSame('Pending', $order->fresh()->status);
    }

    public function test_a_payment_for_the_wrong_amount_is_not_fulfilled(): void
    {
        $order = $this->makeOrder();

        $this->deliver('payment.completed', [
            'id' => 'PUETcMUOKStjZsCb',
            'status' => 'paid',
            'amount' => '0.01',
        ])->assertSuccessful();

        $this->assertSame('Pending', $order->fresh()->status);
        $this->assertSame(Payment::STATUS_PENDING, $order->fresh()->payment->status);
    }

    public function test_an_unknown_payment_id_is_acknowledged_without_side_effects(): void
    {
        $order = $this->makeOrder();

        $this->deliver('payment.completed', [
            'id' => 'not_a_payment_of_ours',
            'status' => 'paid',
            'amount' => '1.50',
        ])->assertSuccessful();

        $this->assertSame('Pending', $order->fresh()->status);
    }
}
