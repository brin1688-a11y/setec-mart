<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);

        $category = Category::create(['name' => 'Dairy']);

        $this->product = Product::create([
            'category_id' => $category->id,
            'name' => 'Fresh Milk',
            'description' => 'Cold',
            'price' => 2.25,
            'stock' => 3, // low, so it shows in stock alerts
            'status' => true,
        ]);
    }

    protected function makeOrder(string $status, string $total, string $method = Payment::METHOD_KHQR, int $qty = 2): Order
    {
        $customer = User::factory()->create(['role' => 'customer']);

        $order = Order::create([
            'user_id' => $customer->id,
            'name' => 'Customer',
            'phone' => '012345678',
            'address' => 'Phnom Penh',
            'status' => $status,
            'total' => $total,
        ]);

        $order->items()->create([
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'price' => $this->product->price,
            'quantity' => $qty,
        ]);

        $order->payment()->create([
            'method' => $method,
            'status' => $status === 'Cancelled' ? Payment::STATUS_PENDING : Payment::STATUS_PAID,
            'amount' => $total,
        ]);

        return $order;
    }

    public function test_a_customer_cannot_open_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_it_renders_with_no_data_at_all(): void
    {
        // A brand new shop must not divide by zero anywhere.
        $this->actingAs($this->admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Dashboard')
            ->assertSee('$0.00');
    }

    public function test_cancelled_orders_are_left_out_of_revenue(): void
    {
        $this->makeOrder('Delivered', '20.00');
        $this->makeOrder('Cancelled', '500.00');

        $response = $this->actingAs($this->admin)->get('/admin');
        $response->assertOk();

        $kpis = $response->viewData('kpis');

        // The cancelled order's money is excluded from every revenue figure.
        // (Its total still shows in the Recent Orders row, which is correct —
        // an admin should see the order that was cancelled.)
        $this->assertSame(20.0, $kpis['revenue_30']);
        $this->assertSame(20.0, $kpis['revenue_total']);
        $this->assertSame(20.0, $kpis['avg_order']);

        // Order counts do include it; only the money is excluded.
        $this->assertSame(2, $kpis['orders_30']);

        $this->assertSame(20.0, collect($response->viewData('trend'))->sum('revenue'));
    }

    public function test_the_trend_covers_fourteen_days_including_empty_ones(): void
    {
        $this->makeOrder('Delivered', '12.00');

        $response = $this->actingAs($this->admin)->get('/admin');

        $trend = $response->viewData('trend');

        $this->assertCount(14, $trend);
        $this->assertSame(now()->subDays(13)->toDateString(), $trend[0]['date']);
        $this->assertSame(now()->toDateString(), $trend[13]['date']);
        $this->assertSame(12.0, $trend[13]['revenue']);
        // Days with no orders are zero-filled rather than missing.
        $this->assertSame(0.0, $trend[0]['revenue']);
    }

    public function test_the_payment_split_separates_khqr_from_cod(): void
    {
        $this->makeOrder('Confirmed', '10.00', Payment::METHOD_KHQR);
        $this->makeOrder('Confirmed', '10.00', Payment::METHOD_KHQR);
        $this->makeOrder('Confirmed', '10.00', Payment::METHOD_COD);

        $split = $this->actingAs($this->admin)->get('/admin')->viewData('paymentSplit');

        $this->assertSame(2, $split['khqr']);
        $this->assertSame(1, $split['cod']);
        $this->assertSame(3, $split['total']);
        $this->assertSame(2, $split['khqr_paid']);
    }

    public function test_best_sellers_ignore_cancelled_orders(): void
    {
        $this->makeOrder('Delivered', '10.00', Payment::METHOD_KHQR, qty: 3);
        $this->makeOrder('Cancelled', '10.00', Payment::METHOD_KHQR, qty: 40);

        $top = $this->actingAs($this->admin)->get('/admin')->viewData('topProducts');

        $this->assertSame('Fresh Milk', $top->first()->product_name);
        // 3 units from the delivered order, not 43.
        $this->assertSame(3, (int) $top->first()->units);
    }

    public function test_pending_orders_surface_as_something_to_act_on(): void
    {
        $this->makeOrder('Pending', '10.00');

        $response = $this->actingAs($this->admin)->get('/admin');

        $attention = collect($response->viewData('needsAttention'));

        $this->assertTrue($attention->contains(fn ($i) => str_contains($i['label'], 'waiting to be confirmed')));
    }

    public function test_low_stock_products_are_listed(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Fresh Milk')
            ->assertSee('3 left');
    }

    public function test_the_thirty_day_delta_compares_against_the_previous_thirty(): void
    {
        // Inside the current window.
        $this->makeOrder('Delivered', '100.00');

        // Inside the previous window.
        $old = $this->makeOrder('Delivered', '50.00');
        $old->forceFill(['created_at' => now()->subDays(45)])->save();

        $kpis = $this->actingAs($this->admin)->get('/admin')->viewData('kpis');

        $this->assertSame(100.0, $kpis['revenue_30']);
        $this->assertSame(100.0, $kpis['revenue_30_delta']); // doubled
    }

    public function test_the_delta_is_null_when_there_is_no_previous_period(): void
    {
        $this->makeOrder('Delivered', '10.00');

        $kpis = $this->actingAs($this->admin)->get('/admin')->viewData('kpis');

        $this->assertNull($kpis['revenue_30_delta']);
    }
}
