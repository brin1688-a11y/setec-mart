<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
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

        // The unfiltered tab counts what it lists, and it does not list
        // cancelled orders — so three, not four.
        $this->assertSame(3, $counts['']);
        $this->assertSame(1, $counts['Pending']);
        $this->assertSame(2, $counts['Confirmed']);
        $this->assertSame(1, $counts['Cancelled']);
        $this->assertSame(0, $counts['Delivered']);
    }

    public function test_a_cancelled_order_is_kept_out_of_the_working_list(): void
    {
        $live = $this->order(['status' => 'Pending']);
        $this->order(['status' => 'Cancelled']);

        // Nothing is waiting to be done on an order the customer called off,
        // so it does not belong among the ones that are.
        $page = $this->actingAs($this->admin)->get('/admin/orders')->assertOk();

        $this->assertCount(1, $page->viewData('orders'));
        $this->assertSame($live->id, $page->viewData('orders')->first()->id);
    }

    public function test_a_cancelled_order_is_still_reachable_on_its_own_tab(): void
    {
        // Kept, not deleted: a customer who disputes a charge is the reason.
        $cancelled = $this->order(['status' => 'Cancelled']);
        $this->order(['status' => 'Pending']);

        $page = $this->actingAs($this->admin)->get('/admin/orders?status=Cancelled')->assertOk();

        $this->assertCount(1, $page->viewData('orders'));
        $this->assertSame($cancelled->id, $page->viewData('orders')->first()->id);

        $this->actingAs($this->admin)
            ->get(route('admin.orders.show', $cancelled))
            ->assertOk();
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

    public function test_the_shop_can_remove_a_cancelled_order_that_took_no_money(): void
    {
        $order = $this->order(['status' => 'Cancelled']);
        $order->payment->update(['status' => Payment::STATUS_CANCELLED]);

        $itemIds = $order->allItems()->pluck('id');

        $this->actingAs($this->admin)
            ->delete(route('admin.orders.destroy', $order))
            ->assertRedirect(route('admin.orders.index', ['status' => 'Cancelled']))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
        $this->assertDatabaseMissing('payments', ['order_id' => $order->id]);

        foreach ($itemIds as $id) {
            $this->assertDatabaseMissing('order_items', ['id' => $id]);
        }
    }

    public function test_an_order_that_took_money_is_refused(): void
    {
        // Paid then cancelled is a refund — the record most likely to be
        // asked about later.
        $order = $this->order(['status' => 'Cancelled']);
        $order->payment->update(['status' => Payment::STATUS_PAID]);

        $this->actingAs($this->admin)
            ->delete(route('admin.orders.destroy', $order))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_a_live_order_cannot_be_removed(): void
    {
        $order = $this->order(['status' => 'Confirmed']);

        $this->actingAs($this->admin)->delete(route('admin.orders.destroy', $order));

        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_a_customer_cannot_remove_an_order(): void
    {
        $order = $this->order(['status' => 'Cancelled']);

        $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->delete(route('admin.orders.destroy', $order))
            ->assertForbidden();

        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_the_button_shows_only_where_removing_is_allowed(): void
    {
        $removable = $this->order(['status' => 'Cancelled']);
        $removable->payment->update(['status' => Payment::STATUS_CANCELLED]);

        $refunded = $this->order(['status' => 'Cancelled']);
        $refunded->payment->update(['status' => Payment::STATUS_PAID]);

        $html = $this->actingAs($this->admin)
            ->get('/admin/orders?status=Cancelled')->assertOk()->getContent();

        // Both rows are listed...
        $this->assertStringContainsString($removable->order_number, $html);
        $this->assertStringContainsString($refunded->order_number, $html);

        // ...and only one offers to remove itself. Checked by the confirm
        // text, which names the order: destroy and show are the same path,
        // told apart only by the method, so matching the URL would find the
        // Open link on both rows.
        $this->assertStringContainsString('Remove '.$removable->order_number.'?', $html);
        $this->assertStringNotContainsString('Remove '.$refunded->order_number.'?', $html);
    }

    public function test_every_removable_cancelled_order_goes_at_once(): void
    {
        $gone = collect(range(1, 3))->map(function () {
            $o = $this->order(['status' => 'Cancelled']);
            $o->payment->update(['status' => Payment::STATUS_CANCELLED]);

            return $o;
        });

        $refunded = $this->order(['status' => 'Cancelled']);
        $refunded->payment->update(['status' => Payment::STATUS_PAID]);

        $live = $this->order(['status' => 'Confirmed']);

        $this->actingAs($this->admin)
            ->delete(route('admin.orders.purge-cancelled'))
            ->assertRedirect(route('admin.orders.index', ['status' => 'Cancelled']))
            ->assertSessionHas('success');

        foreach ($gone as $order) {
            $this->assertDatabaseMissing('orders', ['id' => $order->id]);
            $this->assertDatabaseMissing('payments', ['order_id' => $order->id]);
            $this->assertSame(0, OrderItem::withTrashed()->where('order_id', $order->id)->count());
        }

        // The refund and the live order are untouched.
        $this->assertDatabaseHas('orders', ['id' => $refunded->id]);
        $this->assertDatabaseHas('orders', ['id' => $live->id]);
    }

    public function test_purging_with_nothing_to_purge_says_so(): void
    {
        $this->order(['status' => 'Confirmed']);

        $this->actingAs($this->admin)
            ->delete(route('admin.orders.purge-cancelled'))
            ->assertSessionHas('error');
    }

    public function test_a_customer_cannot_purge(): void
    {
        $order = $this->order(['status' => 'Cancelled']);

        $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->delete(route('admin.orders.purge-cancelled'))
            ->assertForbidden();

        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_the_bulk_button_appears_only_on_the_cancelled_tab(): void
    {
        $order = $this->order(['status' => 'Cancelled']);
        $order->payment->update(['status' => Payment::STATUS_CANCELLED]);

        $this->actingAs($this->admin)->get('/admin/orders')
            ->assertDontSee('Remove all cancelled');

        $this->actingAs($this->admin)->get('/admin/orders?status=Pending')
            ->assertDontSee('Remove all cancelled');

        $this->actingAs($this->admin)->get('/admin/orders?status=Cancelled')
            ->assertSee('Remove all cancelled (1)');
    }
}
