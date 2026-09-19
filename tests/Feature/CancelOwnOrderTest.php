<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CancelOwnOrderTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.cutluy.key' => 'ck_test_key']);

        $this->user = User::factory()->create();

        $category = Category::create(['name' => 'Fruit']);

        $this->product = Product::create([
            'category_id' => $category->id,
            'name' => 'Mango',
            'description' => 'Sweet',
            'price' => 1.50,
            'stock' => 10,      // 2 already reserved by the order below
            'status' => true,
        ]);
    }

    /**
     * An order as checkout leaves it: stock already taken off the shelf.
     */
    protected function order(array $orderOverrides = [], ?array $payment = ['method' => Payment::METHOD_KHQR]): Order
    {
        $order = Order::create(array_merge([
            'user_id' => $this->user->id,
            'name' => 'Sok Dara',
            'phone' => '012345678',
            'province' => 'Phnom Penh',
            'district' => 'Chamkar Mon',
            'commune' => 'Tonle Bassac',
            'address' => 'St. 271',
            'status' => 'Pending',
            'subtotal' => 3.00,
            'discount' => 0,
            'delivery_fee' => 1.50,
            'total' => 4.50,
        ], $orderOverrides));

        $order->items()->create([
            'product_id' => $this->product->id,
            'product_name' => 'Mango',
            'price' => 1.50,
            'quantity' => 2,
        ]);

        $this->product->decrement('stock', 2);

        if ($payment) {
            $order->payment()->create(array_merge([
                'method' => Payment::METHOD_KHQR,
                'status' => Payment::STATUS_PENDING,
                'cutluy_payment_id' => 'PAY'.$order->id,
                'cutluy_status' => 'pending',
                'amount' => 4.50,
                'currency' => 'USD',
                'qr_string' => 'QRPAYLOAD',
            ], $payment));
        }

        return $order->fresh();
    }

    protected function cutLuySays(string $status): void
    {
        Http::fake([
            'cutluy.com/v1/payments/*' => Http::response([
                'id' => 'PAY1',
                'status' => $status,
                'amount' => '4.50',
            ]),
        ]);
    }

    public function test_an_unpaid_order_can_be_cancelled_and_the_stock_comes_back(): void
    {
        $order = $this->order();

        $this->assertSame(8, $this->product->fresh()->stock);

        $this->cutLuySays('pending');

        $this->actingAs($this->user)
            ->post(route('orders.cancel', $order))
            ->assertRedirect(route('orders.index'))
            ->assertSessionHas('success');

        $this->assertSame('Cancelled', $order->fresh()->status);
        $this->assertSame(10, $this->product->fresh()->stock);
    }

    public function test_cancelling_retires_the_qr_so_it_cannot_be_paid_afterwards(): void
    {
        $order = $this->order();

        $this->cutLuySays('pending');

        $this->actingAs($this->user)->post(route('orders.cancel', $order));

        $payment = $order->fresh()->payment;

        $this->assertSame(Payment::STATUS_CANCELLED, $payment->status);
        $this->assertNull($payment->qr_string);
        $this->assertFalse($payment->isAwaitingKhqr());
    }

    public function test_a_cash_on_delivery_order_can_be_cancelled_too(): void
    {
        $order = $this->order(payment: [
            'method' => Payment::METHOD_COD,
            'cutluy_payment_id' => null,
            'qr_string' => null,
        ]);

        Http::fake();

        $this->actingAs($this->user)
            ->post(route('orders.cancel', $order))
            ->assertSessionHas('success');

        $this->assertSame('Cancelled', $order->fresh()->status);
        $this->assertSame(10, $this->product->fresh()->stock);

        // Nothing to ask CutLuy about for a cash order.
        Http::assertNothingSent();
    }

    public function test_money_that_landed_first_wins_over_the_cancel(): void
    {
        $order = $this->order();

        // The customer paid in the seconds before pressing Cancel.
        $this->cutLuySays('paid');

        $this->actingAs($this->user)
            ->post(route('orders.cancel', $order))
            ->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('success');

        $order->refresh();

        $this->assertSame('Confirmed', $order->status);
        $this->assertTrue($order->payment->isPaid());

        // The stock stays sold — it must not go back for a paid order.
        $this->assertSame(8, $this->product->fresh()->stock);
    }

    public function test_a_paid_order_cannot_be_cancelled_from_the_page(): void
    {
        $order = $this->order(payment: [
            'method' => Payment::METHOD_KHQR,
            'status' => Payment::STATUS_PAID,
        ]);

        Http::fake();

        $this->actingAs($this->user)
            ->post(route('orders.cancel', $order))
            ->assertSessionHas('error');

        $this->assertSame('Pending', $order->fresh()->status);
        $this->assertSame(8, $this->product->fresh()->stock);
    }

    public function test_an_order_the_shop_has_started_cannot_be_cancelled(): void
    {
        $order = $this->order(['status' => 'Preparing']);

        Http::fake();

        $this->actingAs($this->user)
            ->post(route('orders.cancel', $order))
            ->assertSessionHas('error');

        $this->assertSame('Preparing', $order->fresh()->status);
        $this->assertSame(8, $this->product->fresh()->stock);
    }

    public function test_cancelling_twice_does_not_return_the_stock_twice(): void
    {
        $order = $this->order();

        $this->cutLuySays('pending');

        $this->actingAs($this->user)->post(route('orders.cancel', $order));
        $this->actingAs($this->user)->post(route('orders.cancel', $order));

        $this->assertSame(10, $this->product->fresh()->stock);
    }

    public function test_one_customer_cannot_cancel_anothers_order(): void
    {
        $order = $this->order();

        Http::fake();

        $this->actingAs(User::factory()->create())
            ->post(route('orders.cancel', $order))
            ->assertForbidden();

        $this->assertSame('Pending', $order->fresh()->status);
    }

    public function test_an_unreachable_provider_still_lets_the_customer_stop(): void
    {
        $order = $this->order();

        Http::fake(['cutluy.com/*' => Http::response([], 500)]);

        $this->actingAs($this->user)
            ->post(route('orders.cancel', $order))
            ->assertSessionHas('success');

        $this->assertSame('Cancelled', $order->fresh()->status);
        $this->assertSame(10, $this->product->fresh()->stock);
    }

    public function test_the_list_offers_cancel_only_while_it_is_allowed(): void
    {
        $open = $this->order();

        $this->actingAs($this->user)
            ->get('/orders')
            ->assertOk()
            ->assertSee(route('orders.cancel', $open), false);

        $open->update(['status' => 'Preparing']);

        $this->actingAs($this->user)
            ->get('/orders')
            ->assertOk()
            ->assertDontSee(route('orders.cancel', $open), false);
    }

    // ---- Clearing finished orders off the list ---------------------------

    public function test_a_finished_order_can_be_cleared_from_the_customers_list(): void
    {
        $order = $this->order(['status' => 'Cancelled']);

        $this->actingAs($this->user)
            ->post(route('orders.hide', $order))
            ->assertRedirect(route('orders.index'))
            ->assertSessionHas('success');

        // Gone from their list...
        $page = $this->actingAs($this->user)->get('/orders');
        $this->assertCount(0, $page->viewData('orders'));
        $page->assertDontSee(route('orders.show', $order), false);

        // ...but the shop still has it, with its takings and its history.
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'Cancelled']);
        $this->assertNotNull($order->fresh()->hidden_at);
    }

    public function test_a_cleared_order_stops_counting_in_the_tabs(): void
    {
        $kept = $this->order(['status' => 'Cancelled']);
        $cleared = $this->order(['status' => 'Cancelled']);

        $this->actingAs($this->user)->post(route('orders.hide', $cleared));

        $counts = $this->actingAs($this->user)->get('/orders')->viewData('counts');

        $this->assertSame(1, $counts['all']);
        $this->assertSame(1, $counts['cancelled']);
        $this->assertSame($kept->id, $this->user->orders()->whereNull('hidden_at')->first()->id);
    }

    public function test_an_order_still_in_progress_cannot_be_cleared_away(): void
    {
        // Hiding a live order would lose the customer their only way back to it.
        $order = $this->order(['status' => 'Preparing']);

        $this->actingAs($this->user)
            ->post(route('orders.hide', $order))
            ->assertSessionHas('error');

        $this->assertNull($order->fresh()->hidden_at);
    }

    public function test_a_delivered_order_can_be_cleared_away(): void
    {
        $order = $this->order(['status' => 'Delivered']);

        $this->actingAs($this->user)
            ->post(route('orders.hide', $order))
            ->assertSessionHas('success');

        $this->assertNotNull($order->fresh()->hidden_at);
    }

    public function test_one_customer_cannot_clear_anothers_order(): void
    {
        $order = $this->order(['status' => 'Cancelled']);

        $this->actingAs(User::factory()->create())
            ->post(route('orders.hide', $order))
            ->assertForbidden();

        $this->assertNull($order->fresh()->hidden_at);
    }

    public function test_the_shop_still_sees_an_order_the_customer_cleared(): void
    {
        $order = $this->order(['status' => 'Cancelled']);

        $this->actingAs($this->user)->post(route('orders.hide', $order));

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get('/admin/orders')
            ->assertOk()
            ->assertSee($order->order_number);
    }

    public function test_the_list_offers_remove_only_on_finished_orders(): void
    {
        $open = $this->order(['status' => 'Pending']);

        $this->actingAs($this->user)
            ->get('/orders')
            ->assertOk()
            ->assertDontSee(route('orders.hide', $open), false);

        $open->update(['status' => 'Delivered']);

        $this->actingAs($this->user)
            ->get('/orders')
            ->assertOk()
            ->assertSee(route('orders.hide', $open), false);
    }

    public function test_a_cash_order_is_not_labelled_as_waiting_for_payment(): void
    {
        // Cash on delivery is waiting for the delivery, not for money.
        $this->order(payment: [
            'method' => Payment::METHOD_COD,
            'cutluy_payment_id' => null,
            'qr_string' => null,
        ]);

        $this->actingAs($this->user)
            ->get('/orders')
            ->assertOk()
            ->assertDontSee('waiting for payment');
    }
}
