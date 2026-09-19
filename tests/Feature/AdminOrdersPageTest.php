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

class AdminOrdersPageTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);

        $category = Category::create(['name' => 'Grocery']);

        $this->product = Product::create([
            'category_id' => $category->id,
            'name' => 'Kampot Pepper',
            'description' => 'Hot',
            'price' => 3.00,
            'stock' => 100,
            'status' => true,
        ]);
    }

    protected function order(array $overrides = [], ?string $paymentStatus = Payment::STATUS_PENDING, string $method = Payment::METHOD_KHQR): Order
    {
        $order = Order::create(array_merge([
            'user_id' => User::factory()->create()->id,
            'name' => 'Sok Dara',
            'phone' => '012345678',
            'province' => 'Phnom Penh',
            'district' => 'Chamkar Mon',
            'commune' => 'Tonle Bassac',
            'address' => 'St. 271',
            'status' => 'Pending',
            'subtotal' => 6.00,
            'discount' => 0,
            'delivery_fee' => 1.50,
            'total' => 7.50,
        ], $overrides));

        $order->items()->create([
            'product_id' => $this->product->id,
            'product_name' => 'Kampot Pepper',
            'price' => 3.00,
            'quantity' => 2,
        ]);

        if ($paymentStatus) {
            $order->payment()->create([
                'method' => $method,
                'status' => $paymentStatus,
                'amount' => 7.50,
                'currency' => 'USD',
            ]);
        }

        return $order->fresh();
    }

    public function test_the_page_shows_what_each_order_is_and_who_it_is_for(): void
    {
        $order = $this->order(['name' => 'Chan Sophea', 'phone' => '077888999']);

        $this->actingAs($this->admin)
            ->get('/admin/orders')
            ->assertOk()
            ->assertSee($order->order_number)
            ->assertSee('Chan Sophea')
            ->assertSee('077 888 999')     // formatted, as the shop would dial it
            ->assertSee('Kampot Pepper')
            ->assertSee('2 items')
            ->assertSee('Phnom Penh')
            ->assertSee('$7.50');
    }

    public function test_the_tabs_carry_a_count_for_each_step(): void
    {
        $this->order(['status' => 'Pending']);
        $this->order(['status' => 'Confirmed']);
        $this->order(['status' => 'Confirmed']);
        $this->order(['status' => 'Cancelled']);

        $page = $this->actingAs($this->admin)->get('/admin/orders')->assertOk();

        $counts = $page->viewData('counts');

        $this->assertSame(4, $counts['']);
        $this->assertSame(1, $counts['Pending']);
        $this->assertSame(2, $counts['Confirmed']);
        $this->assertSame(1, $counts['Cancelled']);
        $this->assertSame(0, $counts['Delivered']);
    }

    public function test_a_status_filter_narrows_the_list(): void
    {
        $pending = $this->order(['status' => 'Pending']);
        $this->order(['status' => 'Delivered']);

        $page = $this->actingAs($this->admin)->get('/admin/orders?status=Pending')->assertOk();

        $this->assertCount(1, $page->viewData('orders'));
        $this->assertSame($pending->id, $page->viewData('orders')->first()->id);
    }

    public function test_a_made_up_status_falls_back_to_everything(): void
    {
        $this->order();
        $this->order();

        $page = $this->actingAs($this->admin)->get('/admin/orders?status=Nonsense')->assertOk();

        $this->assertNull($page->viewData('status'));
        $this->assertCount(2, $page->viewData('orders'));
    }

    public function test_orders_can_be_found_by_reference_name_or_phone(): void
    {
        $wanted = $this->order(['name' => 'Chan Sophea', 'phone' => '077888999']);
        $this->order(['name' => 'Someone Else', 'phone' => '012000111']);

        foreach ([$wanted->order_number, 'sophea', '077888999'] as $term) {
            $page = $this->actingAs($this->admin)->get('/admin/orders?q='.urlencode($term))->assertOk();

            $this->assertCount(1, $page->viewData('orders'), "searching for [$term]");
            $this->assertSame($wanted->id, $page->viewData('orders')->first()->id);
        }
    }

    public function test_search_and_status_narrow_together(): void
    {
        $this->order(['name' => 'Chan Sophea', 'status' => 'Pending']);
        $this->order(['name' => 'Chan Sophea', 'status' => 'Delivered']);

        $page = $this->actingAs($this->admin)
            ->get('/admin/orders?status=Delivered&q=sophea')
            ->assertOk();

        $this->assertCount(1, $page->viewData('orders'));
        $this->assertSame('Delivered', $page->viewData('orders')->first()->status);
    }

    public function test_the_tiles_count_what_is_actually_waiting_on_someone(): void
    {
        // Waiting for money: a KHQR order that has not been paid.
        $this->order(['status' => 'Pending'], Payment::STATUS_PENDING, Payment::METHOD_KHQR);
        // Cash on delivery is waiting for the delivery, not for payment.
        $this->order(['status' => 'Pending'], Payment::STATUS_PENDING, Payment::METHOD_COD);

        $this->order(['status' => 'Confirmed']);
        $this->order(['status' => 'Preparing']);
        $this->order(['status' => 'Out for Delivery']);

        $today = $this->actingAs($this->admin)->get('/admin/orders')->viewData('today');

        $this->assertSame(1, $today['awaiting_payment']);
        $this->assertSame(2, $today['to_prepare']);
        $this->assertSame(1, $today['on_the_road']);
        $this->assertSame(5, $today['orders_today']);
    }

    public function test_cancelled_orders_are_left_out_of_todays_takings(): void
    {
        $this->order(['status' => 'Confirmed', 'total' => 10.00]);
        $this->order(['status' => 'Cancelled', 'total' => 99.00]);

        $today = $this->actingAs($this->admin)->get('/admin/orders')->viewData('today');

        $this->assertSame(10.0, $today['revenue_today']);
    }

    public function test_a_row_offers_the_next_step_it_can_take(): void
    {
        $pending = $this->order(['status' => 'Pending']);

        $this->actingAs($this->admin)
            ->get('/admin/orders')
            ->assertOk()
            // One click from the list, without opening the order.
            ->assertSee(route('admin.orders.status', $pending), false)
            ->assertSee('value="Confirmed"', false);
    }

    public function test_a_finished_order_offers_no_next_step(): void
    {
        $this->order(['status' => 'Delivered']);

        $this->actingAs($this->admin)
            ->get('/admin/orders')
            ->assertOk()
            ->assertDontSee('value="Confirmed"', false);
    }

    public function test_the_list_does_not_query_once_per_order(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->order();
        }

        DB::enableQueryLog();

        $this->actingAs($this->admin)->get('/admin/orders')->assertOk();

        $this->assertLessThan(20, count(DB::getQueryLog()));
    }

    public function test_a_customer_cannot_open_the_admin_orders_page(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->get('/admin/orders')
            ->assertForbidden();
    }
}
