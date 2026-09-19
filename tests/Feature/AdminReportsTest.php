<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminReportsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);

        $this->product = Product::create([
            'category_id' => Category::create(['name' => 'Grocery'])->id,
            'name' => 'Rice',
            'description' => 'Jasmine',
            'price' => 10.00,
            'stock' => 100,
            'status' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function order(string $when, array $attributes = [], int $quantity = 1): Order
    {
        $order = Order::create(array_merge([
            'user_id' => User::factory()->create()->id,
            'name' => 'Sok Dara',
            'phone' => '012345678',
            'province' => 'Phnom Penh',
            'district' => 'Chamkar Mon',
            'commune' => 'Tonle Bassac',
            'address' => 'St. 271',
            'status' => 'Delivered',
            'subtotal' => 10.00,
            'discount' => 0,
            'delivery_fee' => 1.50,
            'total' => 11.50,
        ], $attributes));

        $order->items()->create([
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'price' => 10.00,
            'quantity' => $quantity,
        ]);

        // created_at is set by the database, so move it afterwards.
        $order->forceFill(['created_at' => $when])->saveQuietly();

        return $order;
    }

    public function test_a_customer_cannot_open_it(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->get('/admin/reports')
            ->assertForbidden();
    }

    public function test_it_reports_one_row_per_day_of_the_chosen_month(): void
    {
        $page = $this->actingAs($this->admin)
            ->get('/admin/reports?view=daily&month=2026-02')
            ->assertOk();

        // 2026 is not a leap year.
        $this->assertCount(28, $page->viewData('rows'));
        $this->assertSame('daily', $page->viewData('view'));
    }

    public function test_it_reports_twelve_rows_for_a_year(): void
    {
        $page = $this->actingAs($this->admin)
            ->get('/admin/reports?view=monthly&year=2026')
            ->assertOk();

        $this->assertCount(12, $page->viewData('rows'));
    }

    public function test_revenue_lands_on_the_day_the_order_was_placed(): void
    {
        $this->order('2026-03-05 09:00:00');
        $this->order('2026-03-05 20:00:00');
        $this->order('2026-03-11 09:00:00');

        $rows = collect($this->actingAs($this->admin)
            ->get('/admin/reports?view=daily&month=2026-03')
            ->viewData('rows'))->keyBy('key');

        $this->assertSame(2, $rows['2026-03-05']['orders']);
        $this->assertSame(23.00, $rows['2026-03-05']['revenue']);

        $this->assertSame(1, $rows['2026-03-11']['orders']);
        $this->assertSame(11.50, $rows['2026-03-11']['revenue']);

        // A day with no trade is still a row, so the month reads continuously.
        $this->assertSame(0, $rows['2026-03-06']['orders']);
        $this->assertSame(0.0, $rows['2026-03-06']['revenue']);
    }

    public function test_a_cancelled_order_is_counted_but_never_as_revenue(): void
    {
        $this->order('2026-04-02 10:00:00');
        $this->order('2026-04-02 11:00:00', ['status' => 'Cancelled', 'total' => 99.00]);

        $page = $this->actingAs($this->admin)->get('/admin/reports?view=daily&month=2026-04');

        $row = collect($page->viewData('rows'))->firstWhere('key', '2026-04-02');

        $this->assertSame(1, $row['orders']);
        $this->assertSame(1, $row['cancelled']);
        $this->assertSame(11.50, $row['revenue']);

        $this->assertSame(11.50, $page->viewData('summary')['revenue']);
    }

    public function test_the_months_add_up_to_the_year(): void
    {
        $this->order('2026-01-15 10:00:00');
        $this->order('2026-06-20 10:00:00');
        $this->order('2026-06-21 10:00:00');

        $page = $this->actingAs($this->admin)->get('/admin/reports?view=monthly&year=2026');

        $rows = collect($page->viewData('rows'))->keyBy('key');

        $this->assertSame(11.50, $rows['2026-01']['revenue']);
        $this->assertSame(23.00, $rows['2026-06']['revenue']);
        $this->assertSame(0.0, $rows['2026-07']['revenue']);

        $this->assertSame(34.50, $page->viewData('summary')['revenue']);
        $this->assertSame(3, $page->viewData('summary')['orders']);
    }

    public function test_an_order_with_no_breakdown_still_shows_a_subtotal(): void
    {
        // Orders placed before the shop stored subtotal/discount/delivery keep
        // only a total. Reading those columns literally would report a few
        // cents of subtotal against a real revenue figure.
        // The shape the live database actually holds: subtotal is nullable
        // and was never filled; discount and delivery_fee are NOT NULL and sat
        // at zero.
        $this->order('2026-05-04 10:00:00', [
            'subtotal' => null,
            'discount' => 0,
            'delivery_fee' => 0,
            'total' => 7.25,
        ]);

        $row = collect($this->actingAs($this->admin)
            ->get('/admin/reports?view=daily&month=2026-05')
            ->viewData('rows'))->firstWhere('key', '2026-05-04');

        $this->assertSame(7.25, $row['subtotal']);
        $this->assertSame(7.25, $row['revenue']);
    }

    public function test_units_sold_ignores_a_line_the_customer_removed(): void
    {
        $order = $this->order('2026-07-03 10:00:00', [], quantity: 4);
        $order->items()->first()->delete();          // soft delete

        $this->order('2026-07-03 11:00:00', [], quantity: 2);

        $row = collect($this->actingAs($this->admin)
            ->get('/admin/reports?view=daily&month=2026-07')
            ->viewData('rows'))->firstWhere('key', '2026-07-03');

        $this->assertSame(2, $row['items']);
    }

    public function test_top_products_are_ranked_by_units(): void
    {
        $this->order('2026-08-01 10:00:00', [], quantity: 3);
        $this->order('2026-08-02 10:00:00', [], quantity: 5);

        $top = $this->actingAs($this->admin)
            ->get('/admin/reports?view=daily&month=2026-08')
            ->viewData('topProducts');

        $this->assertSame('Rice', $top->first()->name);
        $this->assertSame(8, (int) $top->first()->units);
    }

    public function test_a_nonsense_period_falls_back_rather_than_failing(): void
    {
        $this->actingAs($this->admin)->get('/admin/reports?view=daily&month=not-a-month')->assertOk();
        $this->actingAs($this->admin)->get('/admin/reports?view=monthly&year=1066')->assertOk();
        $this->actingAs($this->admin)->get('/admin/reports?view=sideways')->assertOk();
    }

    public function test_the_table_can_be_exported(): void
    {
        $this->order('2026-09-09 10:00:00');

        $response = $this->actingAs($this->admin)
            ->get('/admin/reports/export?view=daily&month=2026-09')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=utf-8');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Period,Orders,Cancelled,Items', $csv);
        $this->assertStringContainsString('11.50', $csv);
    }

    public function test_a_customer_cannot_export(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->get('/admin/reports/export')
            ->assertForbidden();
    }
}
