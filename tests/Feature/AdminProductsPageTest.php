<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminProductsPageTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->category = Category::create(['name' => 'Grocery']);
    }

    protected function product(string $name, array $overrides = []): Product
    {
        return Product::create(array_merge([
            'category_id' => $this->category->id,
            'name' => $name,
            'description' => 'Something',
            'price' => 10.00,
            'stock' => 50,
            'status' => true,
        ], $overrides));
    }

    protected function sell(Product $product, int $quantity, string $status = 'Delivered'): void
    {
        $order = Order::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Sok Dara',
            'phone' => '012345678',
            'province' => 'Phnom Penh',
            'district' => 'Chamkar Mon',
            'commune' => 'Tonle Bassac',
            'address' => 'St. 271',
            'status' => $status,
            'subtotal' => 10, 'discount' => 0, 'delivery_fee' => 0, 'total' => 10,
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'price' => $product->price,
            'quantity' => $quantity,
        ]);
    }

    public function test_a_customer_cannot_open_it(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->get('/admin/products')
            ->assertForbidden();
    }

    public function test_the_filters_count_what_is_behind_them(): void
    {
        $this->product('Normal');
        $this->product('Low', ['stock' => 4]);
        $this->product('Gone', ['stock' => 0]);
        $this->product('Hidden', ['status' => false]);
        $this->product('Pinned', ['position' => 1]);
        $this->product('Cheap now', ['price' => 10, 'sale_price' => 6]);

        $counts = $this->actingAs($this->admin)->get('/admin/products')->viewData('counts');

        $this->assertSame(6, $counts['']);
        $this->assertSame(1, $counts['low']);
        $this->assertSame(1, $counts['out']);
        $this->assertSame(1, $counts['hidden']);
        $this->assertSame(1, $counts['featured']);
        $this->assertSame(1, $counts['on_sale']);
    }

    public function test_a_scheduled_promotion_is_not_counted_as_running(): void
    {
        $this->product('Later', ['price' => 10, 'sale_price' => 6, 'sale_starts_at' => now()->addWeek()]);

        $counts = $this->actingAs($this->admin)->get('/admin/products')->viewData('counts');

        $this->assertSame(0, $counts['on_sale']);
    }

    public function test_each_filter_narrows_the_list(): void
    {
        $this->product('Normal');
        $this->product('Gone', ['stock' => 0]);

        $page = $this->actingAs($this->admin)->get('/admin/products?filter=out')->assertOk();

        $this->assertCount(1, $page->viewData('products'));
        $this->assertSame('Gone', $page->viewData('products')->first()->name);
    }

    public function test_a_made_up_filter_falls_back_to_all(): void
    {
        $this->product('Anything');

        $page = $this->actingAs($this->admin)->get('/admin/products?filter=nonsense')->assertOk();

        $this->assertSame('', $page->viewData('filter'));
        $this->assertCount(1, $page->viewData('products'));
    }

    public function test_units_sold_ignores_cancelled_orders(): void
    {
        $product = $this->product('Rice');

        $this->sell($product, 5);
        $this->sell($product, 99, status: 'Cancelled');

        $row = $this->actingAs($this->admin)->get('/admin/products')
            ->viewData('products')->firstWhere('id', $product->id);

        $this->assertSame(5, (int) $row->units_sold);
    }

    public function test_it_can_be_sorted_by_best_selling(): void
    {
        $quiet = $this->product('Quiet');
        $busy = $this->product('Busy');

        $this->sell($busy, 20);
        $this->sell($quiet, 1);

        $first = $this->actingAs($this->admin)->get('/admin/products?sort=best')
            ->viewData('products')->first();

        $this->assertSame($busy->id, $first->id);
    }

    public function test_the_stock_value_uses_the_promotion_price(): void
    {
        // 10 x $6 on promotion, not 10 x $10.
        $this->product('On sale', ['price' => 10, 'sale_price' => 6, 'stock' => 10]);

        $summary = $this->actingAs($this->admin)->get('/admin/products')->viewData('summary');

        $this->assertSame(60.0, $summary['stock_value']);
    }

    public function test_a_hidden_product_is_left_out_of_the_stock_value(): void
    {
        $this->product('Visible', ['price' => 5, 'stock' => 10]);
        $this->product('Hidden', ['price' => 99, 'stock' => 10, 'status' => false]);

        $summary = $this->actingAs($this->admin)->get('/admin/products')->viewData('summary');

        $this->assertSame(50.0, $summary['stock_value']);
    }

    public function test_visibility_can_be_toggled_from_the_list(): void
    {
        $product = $this->product('Rice');

        $this->actingAs($this->admin)
            ->patch(route('admin.products.toggle', $product))
            ->assertSessionHas('success');

        $this->assertFalse($product->fresh()->status);

        $this->actingAs($this->admin)->patch(route('admin.products.toggle', $product));

        $this->assertTrue($product->fresh()->status);
    }

    public function test_a_customer_cannot_toggle_a_product(): void
    {
        $product = $this->product('Rice');

        $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->patch(route('admin.products.toggle', $product))
            ->assertForbidden();

        $this->assertTrue($product->fresh()->status);
    }

    public function test_search_still_works_alongside_a_filter(): void
    {
        $this->product('Apple juice', ['stock' => 0]);
        $this->product('Apple pie');
        $this->product('Bread', ['stock' => 0]);

        $page = $this->actingAs($this->admin)->get('/admin/products?filter=out&search=apple')->assertOk();

        $this->assertCount(1, $page->viewData('products'));
        $this->assertSame('Apple juice', $page->viewData('products')->first()->name);
    }

    public function test_the_list_does_not_query_once_per_product(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->product('Product '.$i);
        }

        DB::enableQueryLog();

        $this->actingAs($this->admin)->get('/admin/products')->assertOk();

        $this->assertLessThan(25, count(DB::getQueryLog()));
    }
}
