<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CutLuyCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.cutluy.key' => 'ck_test_key']);

        $this->user = User::factory()->create();

        $category = Category::create(['name' => 'Produce']);

        $this->product = Product::create([
            'category_id' => $category->id,
            'name' => 'Mango',
            'description' => 'Sweet',
            'price' => 1.50,
            'stock' => 10,
            'status' => 'active',
        ]);

        $cart = Cart::create(['user_id' => $this->user->id]);

        $cart->items()->create([
            'product_id' => $this->product->id,
            'quantity' => 1,
        ]);
    }

    /**
     * A complete Cambodian delivery address, which checkout now requires.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function deliveryDetails(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Sok',
            'phone' => '012345678',
            'province' => 'Phnom Penh',
            'district' => 'Chamkar Mon',
            'commune' => 'Tonle Bassac',
            'address' => 'Phum 4, St. 271',
        ], $overrides);
    }

    protected function checkout(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->post('/checkout', $this->deliveryDetails([
            'payment_method' => 'khqr',
        ]));
    }

    public function test_it_creates_a_cutluy_payment_and_sends_the_customer_to_the_qr(): void
    {
        Http::fake([
            'cutluy.com/v1/payments' => Http::response([
                'id' => 'PUETcMUOKStjZsCb',
                'status' => 'pending',
                'amount' => '1.50',
                'currency' => 'USD',
                'checkout_url' => 'https://cutluy.com/pay/PUETcMUOKStjZsCb',
                'qr_string' => '00020101021229...6304AB12',
            ], 201),
        ]);

        $this->checkout()->assertRedirect();

        $order = Order::first();

        $this->assertNotNull($order);
        $this->assertSame('Pending', $order->status);
        $this->assertSame('PUETcMUOKStjZsCb', $order->payment->cutluy_payment_id);
        $this->assertSame(Payment::STATUS_PENDING, $order->payment->status);
        $this->assertSame('00020101021229...6304AB12', $order->payment->qr_string);

        // The cart is emptied only once there is a QR to pay with.
        $this->assertSame(0, $this->user->cart->items()->count());

        // $1.50 for the mango plus $1.50 Phnom Penh delivery — the order is
        // under the $20 free-delivery threshold.
        $this->assertSame('3.00', $order->total);

        Http::assertSent(function ($request) use ($order) {
            return $request->hasHeader('Authorization', 'Bearer ck_test_key')
                && $request['amount'] === 3.0
                && $request['reference_id'] === 'order_' . $order->id
                && $request['idempotency_key'] === 'order_' . $order->id;
        });
    }

    public function test_the_pay_page_renders_the_khqr_card(): void
    {
        Http::fake([
            'cutluy.com/v1/payments' => Http::response([
                'id' => 'PUETcMUOKStjZsCb',
                'status' => 'pending',
                'amount' => '1.50',
                'currency' => 'USD',
                'checkout_url' => 'https://cutluy.com/pay/PUETcMUOKStjZsCb',
                'qr_string' => '00020101021229KHQRTESTPAYLOAD6304AB12',
                'expires_at' => '2026-09-17T12:55:59.000Z',
            ], 201),
        ]);

        $this->checkout();

        $order = Order::first();

        $this->actingAs($this->user)
            ->get('/orders/'.$order->order_number.'/pay')
            ->assertOk()
            ->assertSee('KHQR')
            ->assertSee('khqrCanvas')
            // The EMV payload has to reach the page for the QR to be drawable.
            ->assertSee('00020101021229KHQRTESTPAYLOAD6304AB12', false);

        $this->assertNotNull($order->payment->expires_at);
    }

    public function test_the_providers_utc_expiry_is_converted_to_shop_time(): void
    {
        // CutLuy quotes expiry in UTC. The shop runs on Phnom Penh time, so a
        // value stored unconverted lands seven hours in the past and a QR that
        // has just been issued reads as already expired.
        config(['app.timezone' => 'Asia/Phnom_Penh']);

        Http::fake([
            'cutluy.com/v1/payments' => Http::response([
                'id' => 'PUETcMUOKStjZsCb',
                'status' => 'pending',
                'amount' => '1.50',
                'qr_string' => '00020101021229KHQRTESTPAYLOAD6304AB12',
                'expires_at' => '2026-09-17T12:55:59.000Z',
            ], 201),
        ]);

        $this->checkout();

        $expires = Order::first()->payment->expires_at;

        // 12:55:59 UTC is 19:55:59 in Phnom Penh, not 12:55:59.
        $this->assertSame('2026-09-17 19:55:59', $expires->format('Y-m-d H:i:s'));
    }

    public function test_an_expiry_that_carries_no_offset_is_left_alone(): void
    {
        config(['app.timezone' => 'Asia/Phnom_Penh']);

        $payment = new \App\Models\Payment;
        $payment->expires_at = '2026-09-17 19:55:59';

        $this->assertSame('2026-09-17 19:55:59', $payment->getAttributes()['expires_at']);

        $payment->expires_at = null;

        $this->assertNull($payment->getAttributes()['expires_at']);
    }

    public function test_another_customer_cannot_open_the_pay_page(): void
    {
        Http::fake([
            'cutluy.com/v1/payments' => Http::response([
                'id' => 'PUETcMUOKStjZsCb',
                'status' => 'pending',
                'amount' => '1.50',
                'qr_string' => '0002010102122',
                'checkout_url' => 'https://cutluy.com/pay/x',
            ], 201),
        ]);

        $this->checkout();

        $this->actingAs(User::factory()->create())
            ->get('/orders/'.Order::first()->order_number.'/pay')
            ->assertForbidden();
    }

    public function test_a_quota_error_rolls_the_order_back_and_keeps_the_cart(): void
    {
        Http::fake([
            'cutluy.com/v1/payments' => Http::response([
                'error' => 'quota_exceeded',
                'message' => 'Payment quota exhausted.',
            ], 402),
        ]);

        $this->checkout()
            ->assertRedirect()
            ->assertSessionHasErrors('payment_method');

        $this->assertSame(0, Order::count());
        $this->assertSame(10, $this->product->fresh()->stock);
        $this->assertSame(1, $this->user->cart->items()->count());
    }

    public function test_a_rate_limit_error_tells_the_customer_how_long_to_wait(): void
    {
        Http::fake([
            'cutluy.com/v1/payments' => Http::response(
                ['error' => 'rate_limited', 'message' => 'Too many requests.'],
                429,
                ['Retry-After' => '30'],
            ),
        ]);

        $this->checkout()->assertSessionHasErrors('payment_method');

        $errors = session('errors')->get('payment_method');

        $this->assertStringContainsString('30 seconds', $errors[0]);
        $this->assertSame(0, Order::count());
        $this->assertSame(10, $this->product->fresh()->stock);
    }

    public function test_an_unauthorized_key_does_not_leave_a_dangling_order(): void
    {
        Http::fake([
            'cutluy.com/v1/payments' => Http::response(
                ['error' => 'unauthorized', 'message' => 'Invalid API key.'],
                401,
            ),
        ]);

        $this->checkout()->assertSessionHasErrors('payment_method');

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(10, $this->product->fresh()->stock);
    }

    public function test_cash_on_delivery_still_works_without_touching_cutluy(): void
    {
        Http::fake();

        $this->actingAs($this->user)
            ->post('/checkout', $this->deliveryDetails(['payment_method' => 'cod']))
            ->assertRedirect();

        $order = Order::first();

        $this->assertSame(Payment::METHOD_COD, $order->payment->method);
        $this->assertSame(9, $this->product->fresh()->stock);

        Http::assertNothingSent();
    }
}
