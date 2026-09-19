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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The pay page has to settle an order even when no webhook ever arrives and
 * no queue worker is running — the customer has paid and is staring at a QR.
 */
class KhqrPayPageTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Product $product;

    protected Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.cutluy.key' => 'ck_test_key']);

        $this->user = User::factory()->create();

        $category = Category::create(['name' => 'Meat']);

        $this->product = Product::create([
            'category_id' => $category->id,
            'name' => 'Pork Ribs',
            'description' => 'Tender',
            'price' => 4.40,
            'stock' => 9,
            'status' => true,
        ]);

        $this->order = Order::create([
            'user_id' => $this->user->id,
            'name' => 'Soat',
            'phone' => '012345678',
            'address' => 'Phnom Penh',
            'status' => 'Pending',
            'total' => '4.40',
        ]);

        $this->order->items()->create([
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'price' => $this->product->price,
            'quantity' => 1,
        ]);

        $this->order->payment()->create([
            'method' => Payment::METHOD_KHQR,
            'status' => Payment::STATUS_PENDING,
            'cutluy_payment_id' => 'PAYID123',
            'cutluy_status' => 'pending',
            'amount' => '4.40',
            'currency' => 'USD',
            'qr_string' => '00020101021229KHQR6304AB12',
        ]);
    }

    protected function fakeRemote(string $status): void
    {
        Http::fake([
            'cutluy.com/v1/payments/*' => Http::response([
                'id' => 'PAYID123',
                'status' => $status,
                'amount' => '4.40',
                'currency' => 'USD',
                'reference_id' => 'order_' . $this->order->id,
                'expires_at' => now()->addMinutes(5)->toIso8601String(),
            ], 200),
        ]);
    }

    public function test_the_status_poll_settles_a_paid_order_with_no_webhook_and_no_worker(): void
    {
        // A real queue connection, so anything merely dispatched would sit in
        // the jobs table untouched (there is no worker in a test run). The
        // order still has to settle.
        config(['queue.default' => 'database']);

        $this->fakeRemote('paid');

        $this->actingAs($this->user)
            ->getJson('/orders/'.$this->order->order_number.'/pay/status')
            ->assertOk()
            ->assertJson([
                'status' => Payment::STATUS_PAID,
                'paid' => true,
            ])
            ->assertJsonPath('redirect', route('orders.show', $this->order));

        $this->assertSame('Confirmed', $this->order->fresh()->status);
        $this->assertSame(Payment::STATUS_PAID, $this->order->fresh()->payment->status);

        // Nothing was left for a worker to pick up.
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_a_scanned_payment_is_reported_but_not_settled(): void
    {
        $this->fakeRemote('scanned');

        $this->actingAs($this->user)
            ->getJson('/orders/'.$this->order->order_number.'/pay/status')
            ->assertOk()
            ->assertJson(['paid' => false, 'provider_status' => 'scanned']);

        $this->assertSame('Pending', $this->order->fresh()->status);
    }

    public function test_an_expired_payment_cancels_and_restores_stock(): void
    {
        $this->fakeRemote('expired');

        $this->actingAs($this->user)
            ->getJson('/orders/'.$this->order->order_number.'/pay/status')
            ->assertOk()
            ->assertJson(['status' => Payment::STATUS_EXPIRED]);

        $this->assertSame('Cancelled', $this->order->fresh()->status);
        $this->assertSame(10, $this->product->fresh()->stock);
    }

    public function test_repeated_polling_is_throttled_to_one_api_call(): void
    {
        $this->fakeRemote('pending');

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($this->user)
                ->getJson('/orders/'.$this->order->order_number.'/pay/status')
                ->assertOk();
        }

        // Five polls, one API read — the rest hit the 5-second throttle.
        Http::assertSentCount(1);
    }

    public function test_a_settled_order_stops_calling_the_api_entirely(): void
    {
        $this->order->payment->update(['status' => Payment::STATUS_PAID]);

        Http::fake();

        $this->actingAs($this->user)
            ->getJson('/orders/'.$this->order->order_number.'/pay/status')
            ->assertOk()
            ->assertJson(['paid' => true]);

        Http::assertNothingSent();
    }

    public function test_the_page_still_works_when_cutluy_is_unreachable(): void
    {
        Http::fake(['cutluy.com/*' => Http::response(['error' => 'server_error'], 500)]);

        $this->actingAs($this->user)
            ->getJson('/orders/'.$this->order->order_number.'/pay/status')
            ->assertOk()
            ->assertJson(['paid' => false]);
    }

    public function test_the_order_page_names_the_method_khqr_without_the_provider(): void
    {
        $this->order->payment->update(['status' => Payment::STATUS_PAID]);

        $response = $this->actingAs($this->user)->get('/orders/'.$this->order->order_number);

        $response->assertOk()->assertSee('KHQR');

        // CutLuy is how we route the payment, not something the customer
        // should ever read on their own order.
        $response->assertDontSee('CutLuy');
    }

    public function test_buy_now_adds_the_item_and_goes_straight_to_checkout(): void
    {
        Cart::create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->post('/cart/add/' . $this->product->id, ['quantity' => 2, 'action' => 'buy_now'])
            ->assertRedirect(route('checkout.index'));

        $this->assertSame(2, $this->user->cart->items()->first()->quantity);
    }

    public function test_add_to_cart_without_buy_now_stays_put(): void
    {
        Cart::create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->from('/products/' . $this->product->id)
            ->post('/cart/add/' . $this->product->id, ['quantity' => 1])
            ->assertRedirect('/products/' . $this->product->id);
    }
}
