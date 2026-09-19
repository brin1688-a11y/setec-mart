<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogueOrderTest extends TestCase
{
    use RefreshDatabase;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create(['name' => 'Grocery']);
    }

    protected function product(string $name, array $overrides = []): Product
    {
        return Product::create(array_merge([
            'category_id' => $this->category->id,
            'name' => $name,
            'description' => 'Something',
            'price' => 5.00,
            'stock' => 50,
            'status' => true,
        ], $overrides));
    }

    /**
     * Sell a product, through an order that counts.
     */
    protected function sell(Product $product, int $quantity, string $status = 'Delivered'): Order
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
            'subtotal' => $product->price * $quantity,
            'discount' => 0,
            'delivery_fee' => 0,
            'total' => $product->price * $quantity,
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'price' => $product->price,
            'quantity' => $quantity,
        ]);

        return $order;
    }

    // ---- Popular ---------------------------------------------------------

    public function test_popular_ranks_by_what_has_actually_been_bought(): void
    {
        $quiet = $this->product('Quiet');
        $steady = $this->product('Steady');
        $bestseller = $this->product('Bestseller');

        $this->sell($steady, 3);
        $this->sell($bestseller, 10);

        $this->assertSame(
            ['Bestseller', 'Steady', 'Quiet'],
            Product::where('status', true)->popular()->pluck('name')->all()
        );
    }

    public function test_a_cancelled_order_does_not_make_a_product_popular(): void
    {
        $real = $this->product('Really sold');
        $fake = $this->product('Ordered then cancelled');

        $this->sell($real, 2);
        $this->sell($fake, 99, status: 'Cancelled');

        $this->assertSame(
            'Really sold',
            Product::where('status', true)->popular()->first()->name
        );
    }

    public function test_a_line_the_customer_removed_does_not_count(): void
    {
        $kept = $this->product('Kept');
        $removed = $this->product('Removed from the order');

        $this->sell($kept, 1);
        $order = $this->sell($removed, 50);
        $order->items->first()->delete();   // soft-deleted, as removeItem does

        $this->assertSame(
            'Kept',
            Product::where('status', true)->popular()->first()->name
        );
    }

    public function test_products_with_no_sales_still_appear(): void
    {
        $this->product('Sold');
        $this->product('Never sold');

        $this->sell(Product::where('name', 'Sold')->first(), 1);

        // A quiet shop should not show an empty page.
        $this->assertCount(2, Product::where('status', true)->popular()->get());
    }

    public function test_the_home_page_only_claims_popularity_once_there_are_sales(): void
    {
        $product = $this->product('Anything');

        $this->get('/')->assertOk()->assertSee('New In')->assertDontSee('Popular Products');

        $this->sell($product, 1);

        $this->get('/')->assertOk()->assertSee('Popular Products');
    }

    // ---- The catalogue's own order ---------------------------------------

    public function test_the_default_order_is_not_simply_newest_first(): void
    {
        foreach (range(1, 20) as $n) {
            $this->product('Product '.$n);
        }

        $newest = Product::orderByDesc('id')->pluck('name')->all();
        $shown = $this->get('/products')->assertOk()->viewData('products')->pluck('name')->all();

        $this->assertNotSame(array_slice($newest, 0, count($shown)), $shown);
    }

    public function test_the_order_holds_still_while_paging_through_it(): void
    {
        foreach (range(1, 30) as $n) {
            $this->product('Product '.$n);
        }

        // A plain RANDOM() would re-draw per query, repeating and skipping
        // products between pages.
        $first = $this->get('/products')->viewData('products')->pluck('id')->all();
        $again = $this->get('/products')->viewData('products')->pluck('id')->all();

        $this->assertSame($first, $again);

        $second = $this->get('/products?page=2')->viewData('products')->pluck('id')->all();

        $this->assertEmpty(array_intersect($first, $second), 'pages must not overlap');
    }

    public function test_a_pinned_product_still_leads_the_list(): void
    {
        foreach (range(1, 15) as $n) {
            $this->product('Product '.$n);
        }

        $this->product('Pick me', ['position' => 1]);

        $this->assertSame(
            'Pick me',
            $this->get('/products')->viewData('products')->first()->name
        );
    }

    public function test_every_sort_option_works(): void
    {
        $cheap = $this->product('Cheap', ['price' => 1.00]);
        $dear = $this->product('Dear', ['price' => 99.00]);
        $this->sell($dear, 5);

        $cases = [
            'price_asc' => 'Cheap',
            'price_desc' => 'Dear',
            'popular' => 'Dear',
            'name' => 'Cheap',
            'newest' => 'Dear',
        ];

        foreach ($cases as $sort => $expected) {
            $first = $this->get('/products?sort='.$sort)->assertOk()
                ->viewData('products')->first()->name;

            $this->assertSame($expected, $first, "sort={$sort}");
        }
    }

    public function test_a_made_up_sort_falls_back_to_the_default(): void
    {
        $this->product('Anything');

        $page = $this->get('/products?sort=nonsense')->assertOk();

        $this->assertSame('recommended', $page->viewData('sort'));
    }

    public function test_the_sort_survives_a_search(): void
    {
        $this->product('Apple juice', ['price' => 9.00]);
        $this->product('Apple pie', ['price' => 2.00]);
        $this->product('Bread', ['price' => 1.00]);

        $page = $this->get('/products?search=apple&sort=price_asc')->assertOk();

        $this->assertSame(['Apple pie', 'Apple juice'], $page->viewData('products')->pluck('name')->all());
    }
}
