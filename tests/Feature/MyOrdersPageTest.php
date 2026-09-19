<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MyOrdersPageTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $category = Category::create(['name' => 'Fruit']);

        $this->product = Product::create([
            'category_id' => $category->id,
            'name' => 'Kampot Mango',
            'description' => 'Sweet',
            'price' => 1.50,
            'stock' => 100,
            'status' => true,
        ]);
    }

    protected function order(array $overrides = [], int $items = 1): Order
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
            'subtotal' => 1.50,
            'discount' => 0,
            'delivery_fee' => 1.50,
            'total' => 3.00,
        ], $overrides));

        for ($i = 0; $i < $items; $i++) {
            $order->items()->create([
                'product_id' => $this->product->id,
                'product_name' => $i === 0 ? 'Kampot Mango' : "Extra $i",
                'price' => 1.50,
                'quantity' => 2,
            ]);
        }

        return $order;
    }

    public function test_the_list_shows_what_was_bought_not_just_an_id(): void
    {
        $this->order();

        $this->actingAs($this->user)
            ->get('/orders')
            ->assertOk()
            ->assertSee('Kampot Mango')
            ->assertSee('2 items')
            ->assertSee('Phnom Penh')
            ->assertSee('$3.00');
    }

    public function test_the_payment_method_and_state_are_shown(): void
    {
        $order = $this->order();
        $order->payment()->create([
            'method' => Payment::METHOD_COD,
            'status' => Payment::STATUS_PENDING,
            'amount' => 3.00,
            'currency' => 'USD',
        ]);

        $this->actingAs($this->user)
            ->get('/orders')
            ->assertOk()
            ->assertSee('Cash on Delivery');
    }

    public function test_an_unpaid_khqr_order_offers_a_way_to_pay(): void
    {
        $order = $this->order();
        $order->payment()->create([
            'method' => Payment::METHOD_KHQR,
            'status' => Payment::STATUS_PENDING,
            'amount' => 3.00,
            'currency' => 'USD',
            'cutluy_payment_id' => 'PAY123',
            'qr_string' => '000201',
        ]);

        $this->actingAs($this->user)
            ->get('/orders')
            ->assertOk()
            ->assertSee('waiting for payment')
            ->assertSee(route('payments.khqr', $order), false);
    }

    public function test_a_khqr_payment_that_never_started_is_not_offered(): void
    {
        // No CutLuy id means there is no QR to go back to; sending the
        // customer to the pay page would only dead-end them.
        $this->order()->payment()->create([
            'method' => Payment::METHOD_KHQR,
            'status' => Payment::STATUS_PENDING,
            'amount' => 3.00,
            'currency' => 'USD',
        ]);

        $this->actingAs($this->user)
            ->get('/orders')
            ->assertOk()
            ->assertDontSee('waiting for payment');
    }

    public function test_a_paid_order_is_not_asked_to_pay_again(): void
    {
        $order = $this->order(['status' => 'Delivered']);
        $order->payment()->create([
            'method' => Payment::METHOD_KHQR,
            'status' => Payment::STATUS_PAID,
            'amount' => 3.00,
            'currency' => 'USD',
        ]);

        $this->actingAs($this->user)
            ->get('/orders')
            ->assertOk()
            ->assertDontSee('waiting for payment')
            ->assertSee('Delivered to');
    }

    public function test_the_tabs_filter_and_count(): void
    {
        $this->order(['status' => 'Preparing']);
        $this->order(['status' => 'Delivered']);
        $this->order(['status' => 'Cancelled']);

        $page = $this->actingAs($this->user)->get('/orders?filter=delivered');

        $page->assertOk()->assertSee('Delivered to');

        // Only the delivered one survives the filter.
        $this->assertCount(1, $page->viewData('orders'));
        $this->assertSame(
            ['all' => 3, 'active' => 1, 'delivered' => 1, 'cancelled' => 1],
            $page->viewData('counts')
        );
    }

    public function test_an_unknown_filter_falls_back_to_all(): void
    {
        $this->order();

        $page = $this->actingAs($this->user)->get('/orders?filter=nonsense');

        $page->assertOk();
        $this->assertSame('all', $page->viewData('filter'));
        $this->assertCount(1, $page->viewData('orders'));
    }

    public function test_an_older_order_without_a_province_still_shows_a_destination(): void
    {
        // Orders placed before the address was captured province-by-province
        // have only the old free-text field. The row must not print an empty
        // "Deliver to".
        $this->order([
            'province' => null,
            'district' => null,
            'commune' => null,
            'address' => 'Phnom Penh
St. 271',
        ]);

        $this->actingAs($this->user)
            ->get('/orders')
            ->assertOk()
            ->assertSee('Phnom Penh')
            // No lead time is invented for an unknown province.
            ->assertDontSee('Arriving');
    }

    public function test_one_customer_never_sees_anothers_orders(): void
    {
        $this->order();

        $stranger = User::factory()->create();

        $page = $this->actingAs($stranger)->get('/orders');

        $page->assertOk()->assertDontSee('Kampot Mango');
        $this->assertCount(0, $page->viewData('orders'));
    }

    public function test_the_list_does_not_query_per_order(): void
    {
        // Five orders must not mean five extra trips for items and payments.
        for ($i = 0; $i < 5; $i++) {
            $this->order()->payment()->create([
                'method' => Payment::METHOD_COD,
                'status' => Payment::STATUS_PENDING,
                'amount' => 3.00,
                'currency' => 'USD',
            ]);
        }

        DB::enableQueryLog();

        $this->actingAs($this->user)->get('/orders')->assertOk();

        $this->assertLessThan(20, count(DB::getQueryLog()));
    }
}
