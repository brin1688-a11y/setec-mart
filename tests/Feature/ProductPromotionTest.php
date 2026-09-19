<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductPromotionTest extends TestCase
{
    use RefreshDatabase;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create(['name' => 'Grocery']);
    }

    protected function product(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'category_id' => $this->category->id,
            'name' => 'Rice 5kg',
            'description' => 'Jasmine',
            'price' => 10.00,
            'stock' => 100,
            'status' => true,
        ], $overrides));
    }

    // ---- What a promotion is ---------------------------------------------

    public function test_a_promotion_price_is_what_the_product_costs(): void
    {
        $product = $this->product(['sale_price' => 7.50]);

        $this->assertTrue($product->isOnSale());
        $this->assertSame(7.50, $product->effectivePrice());
        $this->assertSame(25, $product->discountPercent());
    }

    public function test_a_product_with_no_promotion_costs_its_normal_price(): void
    {
        $product = $this->product();

        $this->assertFalse($product->isOnSale());
        $this->assertSame(10.00, $product->effectivePrice());
        $this->assertSame(0, $product->discountPercent());
    }

    public function test_a_promotion_that_saves_nothing_is_ignored(): void
    {
        // Advertising "was $10, now $10" would be worse than saying nothing.
        foreach ([10.00, 12.00] as $salePrice) {
            $product = $this->product(['sale_price' => $salePrice]);

            $this->assertFalse($product->isOnSale(), "sale price {$salePrice}");
            $this->assertSame(10.00, $product->effectivePrice());
        }
    }

    public function test_a_promotion_waits_for_its_start_date(): void
    {
        $product = $this->product([
            'sale_price' => 7.50,
            'sale_starts_at' => now()->addDay(),
        ]);

        $this->assertFalse($product->isOnSale());
        $this->assertTrue($product->saleIsScheduled());
        $this->assertSame(10.00, $product->effectivePrice());

        $this->travel(2)->days();

        $this->assertTrue($product->fresh()->isOnSale());
        $this->assertSame(7.50, $product->fresh()->effectivePrice());
    }

    public function test_a_promotion_stops_at_its_end_date(): void
    {
        $product = $this->product([
            'sale_price' => 7.50,
            'sale_ends_at' => now()->addHour(),
        ]);

        $this->assertTrue($product->isOnSale());

        $this->travel(2)->hours();

        $this->assertFalse($product->fresh()->isOnSale());
        $this->assertSame(10.00, $product->fresh()->effectivePrice());
    }

    // ---- What the customer is actually charged ---------------------------

    public function test_the_cart_charges_the_promotion_price(): void
    {
        $product = $this->product(['sale_price' => 7.50]);
        $user = User::factory()->create();

        $cart = Cart::create(['user_id' => $user->id]);
        $cart->items()->create(['product_id' => $product->id, 'quantity' => 3]);

        $this->assertSame(22.50, $cart->fresh()->totalPrice());
    }

    public function test_the_order_records_the_price_that_was_charged(): void
    {
        $product = $this->product(['sale_price' => 7.50]);
        $user = User::factory()->create();

        $cart = Cart::create(['user_id' => $user->id]);
        $cart->items()->create(['product_id' => $product->id, 'quantity' => 2]);

        $this->actingAs($user)->post('/checkout', [
            'name' => 'Sok Dara',
            'phone' => '012345678',
            'province' => 'Phnom Penh',
            'district' => 'Chamkar Mon',
            'commune' => 'Tonle Bassac',
            'address' => 'St. 271',
            'payment_method' => 'cod',
        ])->assertRedirect();

        $order = Order::first();

        $this->assertSame('7.50', $order->items->first()->price);
        $this->assertSame('15.00', $order->subtotal);

        // Raising the price afterwards must not rewrite the receipt.
        $product->update(['sale_price' => null, 'price' => 99.00]);

        $this->assertSame('7.50', $order->fresh()->items->first()->price);
    }

    public function test_the_storefront_shows_the_promotion(): void
    {
        $this->product(['sale_price' => 7.50]);

        $this->get('/products')
            ->assertOk()
            ->assertSee('$7.50')      // what it costs
            ->assertSee('$10.00')     // what it was
            ->assertSee('25%');       // how much off
    }

    public function test_a_scheduled_promotion_is_not_advertised_early(): void
    {
        $this->product([
            'sale_price' => 7.50,
            'sale_starts_at' => now()->addWeek(),
        ]);

        $this->get('/products')
            ->assertOk()
            ->assertSee('$10.00')
            ->assertDontSee('$7.50');
    }

    // ---- Setting one up from the admin -----------------------------------

    public function test_an_admin_can_put_a_product_on_promotion(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = $this->product();

        $this->actingAs($admin)->put('/admin/products/'.$product->id, [
            'category_id' => $this->category->id,
            'name' => 'Rice 5kg',
            'description' => 'Jasmine',
            'price' => 10.00,
            'sale_price' => 8.00,
            'stock' => 100,
            'status' => 1,
        ])->assertRedirect();

        $this->assertSame('8.00', $product->fresh()->sale_price);
        $this->assertTrue($product->fresh()->isOnSale());
    }

    public function test_a_promotion_priced_above_the_normal_price_is_refused(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = $this->product();

        $this->actingAs($admin)->put('/admin/products/'.$product->id, [
            'category_id' => $this->category->id,
            'name' => 'Rice 5kg',
            'price' => 10.00,
            'sale_price' => 15.00,
            'stock' => 100,
            'status' => 1,
        ])->assertSessionHasErrors('sale_price');

        $this->assertNull($product->fresh()->sale_price);
    }

    public function test_an_end_before_the_start_is_refused(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = $this->product();

        $this->actingAs($admin)->put('/admin/products/'.$product->id, [
            'category_id' => $this->category->id,
            'name' => 'Rice 5kg',
            'price' => 10.00,
            'sale_price' => 8.00,
            'sale_starts_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
            'sale_ends_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'stock' => 100,
            'status' => 1,
        ])->assertSessionHasErrors('sale_ends_at');
    }

    public function test_clearing_the_price_clears_the_dates_with_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $product = $this->product([
            'sale_price' => 8.00,
            'sale_starts_at' => now()->subDay(),
            'sale_ends_at' => now()->addDay(),
        ]);

        $this->actingAs($admin)->put('/admin/products/'.$product->id, [
            'category_id' => $this->category->id,
            'name' => 'Rice 5kg',
            'price' => 10.00,
            'sale_price' => '',
            'stock' => 100,
            'status' => 1,
        ])->assertRedirect();

        $product->refresh();

        // Leaving the dates behind would arm a promotion nobody asked for.
        $this->assertNull($product->sale_price);
        $this->assertNull($product->sale_starts_at);
        $this->assertNull($product->sale_ends_at);
    }

    // ---- What the shop shows first ---------------------------------------

    public function test_the_shop_can_pull_a_product_to_the_front(): void
    {
        $first = $this->product(['name' => 'Oldest but featured']);
        $this->product(['name' => 'Newer']);
        $this->product(['name' => 'Newest']);

        // Newest-first by default, so the oldest is last.
        $before = Product::inShopOrder()->pluck('name')->all();
        $this->assertSame('Oldest but featured', end($before));

        $first->update(['position' => 1]);

        // Position 1 jumps it ahead of everything left at the default.
        $after = Product::inShopOrder()->pluck('name')->all();
        $this->assertSame(['Oldest but featured', 'Newest', 'Newer'], $after);
    }

    public function test_products_left_at_zero_keep_their_newest_first_order(): void
    {
        $this->product(['name' => 'A']);
        $this->product(['name' => 'B']);
        $this->product(['name' => 'C']);

        $this->assertSame(['C', 'B', 'A'], Product::inShopOrder()->pluck('name')->all());
    }

    public function test_a_lower_position_comes_first(): void
    {
        $this->product(['name' => 'Third', 'position' => 3]);
        $this->product(['name' => 'First', 'position' => 1]);
        $this->product(['name' => 'Second', 'position' => 2]);
        $this->product(['name' => 'Unnumbered']);

        // Numbered products lead, in order; the rest follow behind.
        $this->assertSame(
            ['First', 'Second', 'Third', 'Unnumbered'],
            Product::inShopOrder()->pluck('name')->all()
        );
    }

    public function test_the_catalogue_page_honours_the_order(): void
    {
        $this->product(['name' => 'Ordinary']);
        $featured = $this->product(['name' => 'Pick me', 'position' => 1]);

        $page = $this->get('/products')->assertOk();

        $this->assertSame($featured->id, $page->viewData('products')->first()->id);
    }

    public function test_an_admin_can_set_the_position(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = $this->product();

        $this->actingAs($admin)->put('/admin/products/'.$product->id, [
            'category_id' => $this->category->id,
            'name' => 'Rice 5kg',
            'price' => 10.00,
            'stock' => 100,
            'position' => 2,
            'status' => 1,
        ])->assertRedirect();

        $this->assertSame(2, $product->fresh()->position);
    }
}
