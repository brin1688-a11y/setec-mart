<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchSuggestTest extends TestCase
{
    use RefreshDatabase;

    protected Category $drinks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->drinks = Category::create(['name' => 'Drinks']);
    }

    protected function product(string $name, array $overrides = []): Product
    {
        return Product::create(array_merge([
            'category_id' => $this->drinks->id,
            'name' => $name,
            'description' => 'Cold',
            'price' => 1.50,
            'stock' => 20,
            'status' => true,
        ], $overrides));
    }

    public function test_typing_part_of_a_name_suggests_the_product(): void
    {
        $this->product('Coca-Cola');
        $this->product('Rice 5kg');

        $body = $this->getJson('/search/suggest?q=cola')->assertOk()->json();

        $this->assertCount(1, $body['products']);
        $this->assertSame('Coca-Cola', $body['products'][0]['name']);
        $this->assertSame('Drinks', $body['products'][0]['category']);
        $this->assertSame('1.50', $body['products'][0]['price']);
    }

    public function test_the_search_is_not_case_sensitive(): void
    {
        $this->product('Coca-Cola');

        foreach (['COCA', 'coca', 'CoCa'] as $term) {
            $this->assertCount(1, $this->getJson('/search/suggest?q='.$term)->json('products'), $term);
        }
    }

    public function test_a_name_that_starts_with_the_term_is_offered_first(): void
    {
        // "Iced Coffee" merely contains "co"; "Coconut Water" begins with it.
        $this->product('Iced Coffee');
        $this->product('Coconut Water');

        $names = collect($this->getJson('/search/suggest?q=co')->json('products'))->pluck('name');

        $this->assertSame('Coconut Water', $names->first());
    }

    public function test_matching_categories_are_offered_too(): void
    {
        $this->product('Coca-Cola');

        $body = $this->getJson('/search/suggest?q=drink')->assertOk()->json();

        $this->assertCount(1, $body['categories']);
        $this->assertSame('Drinks', $body['categories'][0]['name']);
    }

    public function test_one_letter_is_not_enough_to_suggest_anything(): void
    {
        $this->product('Coca-Cola');

        // A single letter matches most of the shop, which is noise.
        $body = $this->getJson('/search/suggest?q=c')->assertOk()->json();

        $this->assertSame([], $body['products']);
        $this->assertSame([], $body['categories']);
    }

    public function test_hidden_products_are_never_suggested(): void
    {
        $this->product('Coca-Cola', ['status' => false]);

        $this->assertSame([], $this->getJson('/search/suggest?q=cola')->json('products'));
    }

    public function test_a_product_out_of_stock_is_shown_but_marked(): void
    {
        $this->product('Coca-Cola', ['stock' => 0]);

        $suggestion = $this->getJson('/search/suggest?q=cola')->json('products.0');

        $this->assertFalse($suggestion['in_stock']);
    }

    public function test_a_promotion_price_is_what_gets_suggested(): void
    {
        $this->product('Coca-Cola', ['price' => 2.00, 'sale_price' => 1.20]);

        $suggestion = $this->getJson('/search/suggest?q=cola')->json('products.0');

        $this->assertSame('1.20', $suggestion['price']);
        $this->assertSame('2.00', $suggestion['was']);
    }

    public function test_the_list_of_suggestions_is_capped(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->product('Cola number '.$i);
        }

        $this->assertCount(6, $this->getJson('/search/suggest?q=cola')->json('products'));
    }

    public function test_a_guest_can_use_it(): void
    {
        $this->product('Coca-Cola');

        $this->getJson('/search/suggest?q=cola')->assertOk();
    }

    public function test_the_search_box_still_works_without_it(): void
    {
        $this->product('Coca-Cola');

        // The dropdown is an accelerator; submitting the form must still search.
        $this->get('/products?search=cola')
            ->assertOk()
            ->assertSee('Coca-Cola');
    }
}
