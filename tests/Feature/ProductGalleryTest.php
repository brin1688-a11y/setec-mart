<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductGalleryTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->category = Category::create(['name' => 'Fruits']);
    }

    protected function product(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'category_id' => $this->category->id,
            'name' => 'Fresh Apple',
            'description' => 'Sweet',
            'price' => 2.50,
            'stock' => 20,
            'status' => true,
        ], $overrides));
    }

    // ---- Creating ---------------------------------------------------------

    public function test_a_product_can_be_created_with_several_uploads(): void
    {
        $this->actingAs($this->admin)->post('/admin/products', [
            'category_id' => $this->category->id,
            'name' => 'Fresh Apple',
            'price' => 2.50,
            'stock' => 20,
            'status' => 1,
            'gallery_files' => [
                UploadedFile::fake()->image('one.jpg'),
                UploadedFile::fake()->image('two.jpg'),
                UploadedFile::fake()->image('three.jpg'),
            ],
        ])->assertRedirect();

        $product = Product::first();

        $this->assertSame(3, $product->images()->count());
        $this->assertSame(3, $product->imageUrls()->count());
        $this->assertTrue($product->hasGallery());
    }

    public function test_pasted_urls_become_gallery_images(): void
    {
        $this->actingAs($this->admin)->post('/admin/products', [
            'category_id' => $this->category->id,
            'name' => 'Fresh Apple',
            'price' => 2.50,
            'stock' => 20,
            'gallery_urls' => "https://example.com/a.jpg\nhttps://example.com/b.jpg",
        ])->assertRedirect();

        $images = Product::first()->images;

        $this->assertSame(2, $images->count());
        // A full URL is used as-is, not treated as a stored path.
        $this->assertSame('https://example.com/a.jpg', $images->first()->url());
    }

    public function test_blank_lines_and_junk_urls_are_ignored(): void
    {
        $this->actingAs($this->admin)->post('/admin/products', [
            'category_id' => $this->category->id,
            'name' => 'Fresh Apple',
            'price' => 2.50,
            'stock' => 20,
            'gallery_urls' => "https://example.com/a.jpg\n\n   \nnot-a-url\n",
        ]);

        $this->assertSame(1, Product::first()->images()->count());
    }

    // ---- Editing ----------------------------------------------------------

    public function test_images_can_be_added_to_an_existing_product(): void
    {
        $product = $this->product();
        $product->images()->create(['path' => 'https://example.com/a.jpg', 'position' => 0]);

        $this->actingAs($this->admin)->put('/admin/products/' . $product->id, [
            'category_id' => $this->category->id,
            'name' => 'Fresh Apple',
            'price' => 2.50,
            'stock' => 20,
            'gallery_urls' => 'https://example.com/b.jpg',
        ]);

        $this->assertSame(2, $product->fresh()->images()->count());
    }

    public function test_ticked_images_are_removed(): void
    {
        $product = $this->product();
        $keep = $product->images()->create(['path' => 'https://example.com/keep.jpg', 'position' => 0]);
        $drop = $product->images()->create(['path' => 'https://example.com/drop.jpg', 'position' => 1]);

        $this->actingAs($this->admin)->put('/admin/products/' . $product->id, [
            'category_id' => $this->category->id,
            'name' => 'Fresh Apple',
            'price' => 2.50,
            'stock' => 20,
            'remove_images' => [$drop->id],
        ]);

        $remaining = $product->fresh()->images;

        $this->assertSame(1, $remaining->count());
        $this->assertSame($keep->id, $remaining->first()->id);
    }

    public function test_one_product_cannot_delete_another_products_image(): void
    {
        $mine = $this->product();
        $theirs = $this->product(['name' => 'Other']);

        $victim = $theirs->images()->create(['path' => 'https://example.com/theirs.jpg', 'position' => 0]);

        $this->actingAs($this->admin)->put('/admin/products/' . $mine->id, [
            'category_id' => $this->category->id,
            'name' => 'Fresh Apple',
            'price' => 2.50,
            'stock' => 20,
            'remove_images' => [$victim->id],
        ]);

        // Still there — removal is scoped to the product being edited.
        $this->assertNotNull($victim->fresh());
    }

    public function test_reordering_changes_which_image_is_the_main_one(): void
    {
        $product = $this->product();
        $first = $product->images()->create(['path' => 'https://example.com/1.jpg', 'position' => 0]);
        $second = $product->images()->create(['path' => 'https://example.com/2.jpg', 'position' => 1]);

        $this->actingAs($this->admin)->put('/admin/products/' . $product->id, [
            'category_id' => $this->category->id,
            'name' => 'Fresh Apple',
            'price' => 2.50,
            'stock' => 20,
            'image_order' => $second->id . ',' . $first->id,
        ]);

        $product->refresh();

        $this->assertSame($second->id, $product->images->first()->id);
        $this->assertSame('https://example.com/2.jpg', $product->imageUrl());
        // The legacy column follows the gallery, so older code stays correct.
        $this->assertSame('https://example.com/2.jpg', $product->image);
    }

    public function test_deleting_a_product_takes_its_images_with_it(): void
    {
        $product = $this->product();
        $product->images()->create(['path' => 'https://example.com/a.jpg', 'position' => 0]);

        $this->actingAs($this->admin)->delete('/admin/products/' . $product->id);

        $this->assertSame(0, \App\Models\ProductImage::count());
    }

    // ---- Storefront -------------------------------------------------------

    public function test_the_product_page_shows_thumbnails_when_there_are_several(): void
    {
        $product = $this->product();
        $product->images()->create(['path' => 'https://example.com/1.jpg', 'position' => 0]);
        $product->images()->create(['path' => 'https://example.com/2.jpg', 'position' => 1]);

        $this->get('/products/' . $product->id)
            ->assertOk()
            ->assertSee('pd-thumb', false)
            ->assertSee('https://example.com/2.jpg', false);
    }

    public function test_a_single_image_shows_no_thumbnail_strip(): void
    {
        $product = $this->product();
        $product->images()->create(['path' => 'https://example.com/1.jpg', 'position' => 0]);

        // The class name lives in the page's CSS either way, so look for the
        // element itself rather than the word.
        $this->get('/products/' . $product->id)
            ->assertOk()
            ->assertDontSee('<div class="pd-thumbs"', false)
            ->assertDontSee('role="tablist"', false);
    }

    public function test_a_product_with_only_the_legacy_column_still_shows_its_image(): void
    {
        // Rows that predate the gallery must keep working.
        $product = $this->product(['image' => 'https://example.com/legacy.jpg']);

        $this->assertSame('https://example.com/legacy.jpg', $product->imageUrl());
        $this->assertSame(1, $product->imageUrls()->count());
        $this->assertFalse($product->hasGallery());
    }
}
