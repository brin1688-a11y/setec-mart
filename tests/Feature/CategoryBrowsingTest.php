<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryBrowsingTest extends TestCase
{
    use RefreshDatabase;

    protected Category $fruits;

    protected Category $dairy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fruits = Category::create(['name' => 'Fruits']);
        $this->dairy = Category::create(['name' => 'Dairy']);

        $this->make($this->fruits, 'Apple', 2.00);
        $this->make($this->fruits, 'Banana', 1.00);
        $this->make($this->dairy, 'Milk', 3.00);

        // Hidden products must not appear anywhere public.
        $this->make($this->fruits, 'Secret Fruit', 9.00, status: false);
    }

    protected function make(Category $category, string $name, float $price, bool $status = true): Product
    {
        return Product::create([
            'category_id' => $category->id,
            'name' => $name,
            'description' => 'x',
            'price' => $price,
            'stock' => 10,
            'status' => $status,
        ]);
    }

    public function test_the_categories_page_lists_every_category(): void
    {
        $this->get('/categories')
            ->assertOk()
            ->assertSee('Fruits')
            ->assertSee('Dairy');
    }

    public function test_the_counts_exclude_hidden_products(): void
    {
        $categories = $this->get('/categories')->viewData('categories');

        $fruits = $categories->firstWhere('name', 'Fruits');

        // Apple and Banana, not the hidden one.
        $this->assertSame(2, $fruits->products_count);
    }

    public function test_a_category_page_shows_only_its_own_products(): void
    {
        $this->get('/categories/' . $this->fruits->id)
            ->assertOk()
            ->assertSee('Apple')
            ->assertSee('Banana')
            ->assertDontSee('Milk');
    }

    public function test_a_category_page_hides_inactive_products(): void
    {
        $this->get('/categories/' . $this->fruits->id)
            ->assertOk()
            ->assertDontSee('Secret Fruit');
    }

    public function test_products_can_be_sorted_by_price(): void
    {
        $cheapFirst = $this->get('/categories/' . $this->fruits->id . '?sort=price_asc')
            ->viewData('products')->pluck('name')->all();

        $this->assertSame(['Banana', 'Apple'], $cheapFirst);

        $dearFirst = $this->get('/categories/' . $this->fruits->id . '?sort=price_desc')
            ->viewData('products')->pluck('name')->all();

        $this->assertSame(['Apple', 'Banana'], $dearFirst);
    }

    public function test_searching_within_a_category_filters_it(): void
    {
        $found = $this->get('/categories/' . $this->fruits->id . '?search=ban')
            ->viewData('products')->pluck('name')->all();

        $this->assertSame(['Banana'], $found);
    }

    public function test_the_category_page_offers_chips_for_the_others(): void
    {
        $this->get('/categories/' . $this->fruits->id)
            ->assertOk()
            ->assertSee(route('categories.show', $this->dairy), false);
    }

    public function test_the_navbar_links_to_the_categories_page(): void
    {
        // It used to be a dead href="#".
        $this->get('/')
            ->assertOk()
            ->assertSee(route('categories.index'), false);
    }

    public function test_an_unknown_category_is_a_404(): void
    {
        $this->get('/categories/99999')->assertNotFound();
    }

    public function test_an_empty_category_says_so_rather_than_showing_nothing(): void
    {
        $empty = Category::create(['name' => 'Frozen']);

        $this->get('/categories/' . $empty->id)
            ->assertOk()
            ->assertSee(__('site.categories.empty_title'));
    }
}
