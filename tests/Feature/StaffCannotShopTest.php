<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffCannotShopTest extends TestCase
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
            'stock' => 50,
            'status' => true,
        ]);
    }

    public function test_an_admin_is_turned_away_from_the_cart(): void
    {
        $this->actingAs($this->admin)
            ->get('/cart')
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('error');
    }

    public function test_an_admin_cannot_add_anything_to_a_cart(): void
    {
        $this->actingAs($this->admin)
            ->post(route('cart.add', $this->product), ['quantity' => 1])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertDatabaseCount('cart_items', 0);
        $this->assertSame(50, $this->product->fresh()->stock);
    }

    public function test_an_admin_cannot_reach_checkout(): void
    {
        $this->actingAs($this->admin)->get('/checkout')->assertRedirect(route('admin.dashboard'));
        $this->actingAs($this->admin)->post('/checkout', [])->assertRedirect(route('admin.dashboard'));

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_an_admin_still_browses_the_storefront(): void
    {
        $this->actingAs($this->admin)->get('/')->assertOk();
        $this->actingAs($this->admin)->get('/products')->assertOk();
        $this->actingAs($this->admin)->get('/categories')->assertOk();
        $this->actingAs($this->admin)->get('/products/'.$this->product->id)->assertOk();
    }

    public function test_the_storefront_offers_an_admin_no_way_to_buy(): void
    {
        foreach (['/', '/products'] as $page) {
            $this->actingAs($this->admin)
                ->get($page)
                ->assertOk()
                ->assertDontSee(route('cart.add', $this->product), false);
        }

        $this->actingAs($this->admin)
            ->get('/products/'.$this->product->id)
            ->assertOk()
            ->assertDontSee(route('cart.add', $this->product), false)
            ->assertSee('Admin accounts cannot place orders');
    }

    public function test_an_admin_has_no_customer_account_area(): void
    {
        // The account pages and order history are for shopping, which a staff
        // account cannot do — so they are refused, not merely hidden.
        $this->actingAs($this->admin)->get('/dashboard')->assertRedirect(route('admin.dashboard'));
        $this->actingAs($this->admin)->get('/orders')->assertRedirect(route('admin.dashboard'));
    }

    public function test_the_menus_offer_an_admin_none_of_it(): void
    {
        $page = $this->actingAs($this->admin)->get('/')->assertOk();

        $page->assertDontSee(route('dashboard'), false)
            ->assertDontSee(route('orders.index'), false)
            // Profile still belongs to them — it is where the password lives.
            ->assertSee(route('profile.index'), false);
    }

    public function test_an_admin_profile_has_no_delivery_details(): void
    {
        // Nowhere to deliver to when the account cannot order.
        $this->actingAs($this->admin)
            ->get('/profile')
            ->assertOk()
            ->assertDontSee(route('profile.delivery'), false);
    }

    public function test_a_customer_keeps_all_of_it(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);

        $this->actingAs($customer)->get('/dashboard')->assertOk();
        $this->actingAs($customer)->get('/orders')->assertOk();

        $this->actingAs($customer)
            ->get('/profile')
            ->assertOk()
            ->assertSee(route('profile.delivery'), false);

        $this->actingAs($customer)
            ->get('/')
            ->assertOk()
            ->assertSee(route('dashboard'), false);
    }

    public function test_a_customer_shops_as_before(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);

        $this->actingAs($customer)
            ->post(route('cart.add', $this->product), ['quantity' => 2])
            ->assertRedirect();

        $this->assertDatabaseCount('cart_items', 1);

        $this->actingAs($customer)->get('/cart')->assertOk();
        $this->actingAs($customer)->get('/checkout')->assertOk();

        $this->actingAs($customer)
            ->get('/products/'.$this->product->id)
            ->assertOk()
            ->assertSee(route('cart.add', $this->product), false);
    }

    public function test_a_guest_is_still_invited_to_sign_in(): void
    {
        $this->get('/products')
            ->assertOk()
            ->assertSee(route('login'), false);
    }
}
