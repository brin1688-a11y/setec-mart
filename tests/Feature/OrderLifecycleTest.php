<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class OrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.cutluy.key' => 'ck_test_key']);

        $this->admin = User::factory()->create(['role' => 'admin']);

        $category = Category::create(['name' => 'Dairy']);

        $this->product = Product::create([
            'category_id' => $category->id,
            'name' => 'Fresh Milk',
            'description' => 'Cold',
            'price' => 2.00,
            'stock' => 8, // 2 already reserved by the order below
            'status' => true,
        ]);
    }

    protected function makeOrder(string $status = 'Pending', string $method = Payment::METHOD_COD, ?string $cutluyId = null): Order
    {
        $order = Order::create([
            'user_id' => User::factory()->create(['role' => 'customer'])->id,
            'name' => 'Customer',
            'phone' => '012345678',
            'address' => 'Phnom Penh',
            'status' => $status,
            'total' => '4.00',
        ]);

        $order->items()->create([
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'price' => $this->product->price,
            'quantity' => 2,
        ]);

        $order->payment()->create([
            'method' => $method,
            'status' => Payment::STATUS_PENDING,
            'cutluy_payment_id' => $cutluyId,
            'amount' => '4.00',
        ]);

        return $order;
    }

    // ---- 1. Admin cancelling returns stock ------------------------------

    public function test_cancelling_an_order_returns_its_stock(): void
    {
        $order = $this->makeOrder();

        $this->actingAs($this->admin)
            ->patch('/admin/orders/'.$order->order_number.'/status', ['status' => 'Cancelled'])
            ->assertRedirect();

        $this->assertSame('Cancelled', $order->fresh()->status);
        $this->assertSame(10, $this->product->fresh()->stock);
    }

    public function test_an_order_cannot_be_cancelled_twice(): void
    {
        $order = $this->makeOrder('Cancelled');

        $this->actingAs($this->admin)
            ->patch('/admin/orders/'.$order->order_number.'/status', ['status' => 'Cancelled'])
            ->assertSessionHas('error');

        // Stock is untouched — no second refund of the same items.
        $this->assertSame(8, $this->product->fresh()->stock);
    }

    public function test_a_delivered_order_can_no_longer_be_changed(): void
    {
        $order = $this->makeOrder('Delivered');

        $this->actingAs($this->admin)
            ->patch('/admin/orders/'.$order->order_number.'/status', ['status' => 'Pending'])
            ->assertSessionHas('error');

        $this->assertSame('Delivered', $order->fresh()->status);
    }

    public function test_status_can_only_move_to_the_next_step(): void
    {
        $order = $this->makeOrder('Pending');

        // Pending cannot jump straight to Delivered.
        $this->actingAs($this->admin)
            ->patch('/admin/orders/'.$order->order_number.'/status', ['status' => 'Delivered'])
            ->assertSessionHasErrors('status');

        $this->assertSame('Pending', $order->fresh()->status);

        $this->actingAs($this->admin)
            ->patch('/admin/orders/'.$order->order_number.'/status', ['status' => 'Confirmed'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Confirmed', $order->fresh()->status);
    }

    public function test_moving_forward_does_not_touch_stock(): void
    {
        $order = $this->makeOrder('Pending');

        $this->actingAs($this->admin)
            ->patch('/admin/orders/'.$order->order_number.'/status', ['status' => 'Confirmed']);

        $this->assertSame(8, $this->product->fresh()->stock);
    }

    // ---- 2. Scheduled reconciliation ------------------------------------

    public function test_reconcile_releases_an_expired_khqr_order(): void
    {
        $order = $this->makeOrder('Pending', Payment::METHOD_KHQR, 'PAYEXPIRED');
        $order->forceFill(['created_at' => now()->subHour()])->save();
        $order->payment->forceFill(['created_at' => now()->subHour()])->save();

        Http::fake(['cutluy.com/v1/payments/*' => Http::response([
            'id' => 'PAYEXPIRED', 'status' => 'expired', 'amount' => '4.00',
        ], 200)]);

        $this->artisan('cutluy:reconcile')->assertExitCode(0);

        $this->assertSame('Cancelled', $order->fresh()->status);
        $this->assertSame(Payment::STATUS_EXPIRED, $order->fresh()->payment->status);
        $this->assertSame(10, $this->product->fresh()->stock);
    }

    public function test_reconcile_confirms_an_order_whose_webhook_was_missed(): void
    {
        $order = $this->makeOrder('Pending', Payment::METHOD_KHQR, 'PAYPAID');
        $order->payment->forceFill(['created_at' => now()->subHour()])->save();

        Http::fake(['cutluy.com/v1/payments/*' => Http::response([
            'id' => 'PAYPAID', 'status' => 'paid', 'amount' => '4.00',
        ], 200)]);

        $this->artisan('cutluy:reconcile')->assertExitCode(0);

        $this->assertSame('Confirmed', $order->fresh()->status);
        $this->assertSame(Payment::STATUS_PAID, $order->fresh()->payment->status);
        // A paid order keeps its stock reserved.
        $this->assertSame(8, $this->product->fresh()->stock);
    }

    public function test_reconcile_never_touches_cash_on_delivery_orders(): void
    {
        $order = $this->makeOrder('Pending', Payment::METHOD_COD);
        $order->payment->forceFill(['created_at' => now()->subDays(3)])->save();

        Http::fake();

        $this->artisan('cutluy:reconcile');

        // COD is legitimately Pending until a human confirms it.
        $this->assertSame('Pending', $order->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_reconcile_leaves_a_checkout_that_is_still_on_screen_alone(): void
    {
        $this->makeOrder('Pending', Payment::METHOD_KHQR, 'PAYFRESH');

        Http::fake();

        $this->artisan('cutluy:reconcile');

        // Created just now — the customer may still be scanning.
        Http::assertNothingSent();
    }

    // ---- 3. Auth throttling ---------------------------------------------

    public function test_login_is_rate_limited(): void
    {
        RateLimiter::clear('');

        User::factory()->create(['email' => 'victim@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'victim@example.com', 'password' => 'guess' . $i])
                ->assertStatus(302);
        }

        // The sixth attempt inside the minute is refused outright.
        $this->post('/login', ['email' => 'victim@example.com', 'password' => 'guess6'])
            ->assertStatus(429);

        $this->assertGuest();
    }

    public function test_register_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/register', ['name' => 'X', 'email' => "spam{$i}@example.com", 'password' => 'x']);
        }

        $this->post('/register', ['name' => 'X', 'email' => 'spam6@example.com', 'password' => 'x'])
            ->assertStatus(429);
    }
}
