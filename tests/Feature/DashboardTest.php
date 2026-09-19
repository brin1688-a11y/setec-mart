<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'customer', 'name' => 'Sok Dara']);

        $category = Category::create(['name' => 'Grocery']);

        $this->product = Product::create([
            'category_id' => $category->id,
            'name' => 'Kampot Pepper',
            'description' => 'Hot',
            'price' => 3.00,
            'stock' => 40,
            'status' => true,
        ]);
    }

    protected function order(array $overrides = [], int $quantity = 2): Order
    {
        $order = Order::create(array_merge([
            'user_id' => $this->user->id,
            'name' => 'Sok Dara',
            'phone' => '012345678',
            'province' => 'Phnom Penh',
            'district' => 'Chamkar Mon',
            'commune' => 'Tonle Bassac',
            'address' => 'St. 271',
            'status' => 'Pending',
            'subtotal' => 3.00 * $quantity,
            'discount' => 0,
            'delivery_fee' => 0,
            'total' => 3.00 * $quantity,
        ], $overrides));

        $order->items()->create([
            'product_id' => $this->product->id,
            'product_name' => 'Kampot Pepper',
            'price' => 3.00,
            'quantity' => $quantity,
        ]);

        return $order->fresh();
    }

    protected function address(array $overrides = []): Address
    {
        return $this->user->addresses()->create(array_merge([
            'label' => 'Home',
            'name' => 'Sok Dara',
            'phone' => '012345678',
            'province' => 'Phnom Penh',
            'district' => 'Chamkar Mon',
            'commune' => 'Tonle Bassac',
            'address' => 'St. 271',
            'is_default' => true,
        ], $overrides));
    }

    // ---- Getting in ------------------------------------------------------

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_the_tabs_are_limited_to_the_ones_that_exist(): void
    {
        $page = $this->actingAs($this->user)->get('/dashboard?tab=nonsense')->assertOk();

        $this->assertSame('overview', $page->viewData('tab'));
    }

    // ---- Overview --------------------------------------------------------

    public function test_the_overview_counts_only_orders_still_on_their_way(): void
    {
        $this->order(['status' => 'Pending']);
        $this->order(['status' => 'Out for Delivery']);
        $this->order(['status' => 'Delivered']);
        $this->order(['status' => 'Cancelled']);

        $this->assertSame(2, $this->actingAs($this->user)->get('/dashboard')->viewData('activeOrders'));
    }

    public function test_loyalty_points_come_from_what_was_actually_spent(): void
    {
        $this->order(['total' => 12.75, 'status' => 'Delivered']);
        $this->order(['total' => 7.50, 'status' => 'Confirmed']);
        // A cancelled order was never paid for, so it earns nothing.
        $this->order(['total' => 99.00, 'status' => 'Cancelled']);

        $this->assertSame(20, $this->actingAs($this->user)->get('/dashboard')->viewData('loyaltyPoints'));
    }

    public function test_only_usable_vouchers_are_offered(): void
    {
        Coupon::create(['code' => 'LIVE', 'type' => 'fixed', 'value' => 2, 'active' => true]);
        Coupon::create(['code' => 'OFF', 'type' => 'fixed', 'value' => 2, 'active' => false]);
        Coupon::create(['code' => 'EXPIRED', 'type' => 'fixed', 'value' => 2, 'active' => true, 'expires_at' => now()->subDay()]);
        Coupon::create(['code' => 'FUTURE', 'type' => 'fixed', 'value' => 2, 'active' => true, 'starts_at' => now()->addWeek()]);
        // used_count is not mass assignable — a redemption counter should not
        // be settable from a form — so it is set directly here.
        Coupon::create(['code' => 'USEDUP', 'type' => 'fixed', 'value' => 2, 'active' => true, 'max_uses' => 5])
            ->forceFill(['used_count' => 5])->save();

        $codes = $this->actingAs($this->user)->get('/dashboard')->viewData('vouchers')->pluck('code');

        $this->assertSame(['LIVE'], $codes->all());
    }

    // ---- Orders tab ------------------------------------------------------

    public function test_the_orders_tab_shows_what_was_bought(): void
    {
        $order = $this->order();

        $this->actingAs($this->user)
            ->get('/dashboard?tab=orders')
            ->assertOk()
            ->assertSee($order->order_number)
            ->assertSee('Kampot Pepper')
            ->assertSee('Reorder');
    }

    public function test_one_customer_never_sees_anothers_orders(): void
    {
        $this->order();

        $stranger = User::factory()->create();

        $page = $this->actingAs($stranger)->get('/dashboard?tab=orders')->assertOk();

        $this->assertCount(0, $page->viewData('orders'));
    }

    // ---- Reorder ---------------------------------------------------------

    public function test_reorder_puts_the_items_back_in_the_cart(): void
    {
        $order = $this->order(quantity: 3);

        $this->actingAs($this->user)
            ->post(route('orders.reorder', $order))
            ->assertRedirect(route('cart.index'))
            ->assertSessionHas('success');

        $cart = Cart::where('user_id', $this->user->id)->firstOrFail();

        $this->assertSame(1, $cart->items()->count());
        $this->assertSame(3, $cart->items()->first()->quantity);
    }

    public function test_reorder_never_asks_for_more_than_the_shelf_holds(): void
    {
        $order = $this->order(quantity: 10);
        $this->product->update(['stock' => 4]);

        $this->actingAs($this->user)->post(route('orders.reorder', $order));

        $this->assertSame(4, Cart::where('user_id', $this->user->id)->first()->items()->first()->quantity);
    }

    public function test_reorder_says_what_it_could_not_bring_back(): void
    {
        $order = $this->order();
        $this->product->update(['stock' => 0]);

        $this->actingAs($this->user)
            ->post(route('orders.reorder', $order))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_reorder_skips_a_product_that_is_no_longer_sold(): void
    {
        $order = $this->order();
        $this->product->update(['status' => false]);

        $this->actingAs($this->user)
            ->post(route('orders.reorder', $order))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_one_customer_cannot_reorder_anothers_order(): void
    {
        $order = $this->order();

        $this->actingAs(User::factory()->create())
            ->post(route('orders.reorder', $order))
            ->assertForbidden();
    }

    public function test_an_admin_cannot_reorder_because_staff_do_not_shop(): void
    {
        $order = $this->order();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('orders.reorder', $order))
            ->assertRedirect(route('admin.dashboard'));
    }

    // ---- Saved addresses -------------------------------------------------

    public function test_an_address_can_be_saved(): void
    {
        $this->actingAs($this->user)->post(route('addresses.store'), [
            'label' => 'Office',
            'name' => 'Sok Dara',
            'phone' => '+855 12 345 678',
            'province' => 'Phnom Penh',
            'district' => 'Chamkar Mon',
            'commune' => 'Tonle Bassac',
            'address' => 'St. 271, House 12B',
        ])->assertSessionHasNoErrors();

        $address = $this->user->addresses()->firstOrFail();

        $this->assertSame('Office', $address->label);
        // Stored in one canonical shape, as checkout does.
        $this->assertSame('012345678', $address->phone);
        // The first one saved has nothing to compete with.
        $this->assertTrue($address->is_default);
    }

    public function test_a_made_up_province_is_refused(): void
    {
        $this->actingAs($this->user)->post(route('addresses.store'), [
            'label' => 'Home',
            'name' => 'Sok Dara',
            'phone' => '012345678',
            'province' => 'Atlantis',
            'district' => 'X',
            'commune' => 'Y',
            'address' => 'Z',
        ])->assertSessionHasErrors('province');

        $this->assertSame(0, $this->user->addresses()->count());
    }

    public function test_making_one_address_default_demotes_the_others(): void
    {
        $home = $this->address();
        $office = $this->address(['label' => 'Office', 'is_default' => false]);

        $this->actingAs($this->user)->patch(route('addresses.default', $office));

        $this->assertFalse($home->fresh()->is_default);
        $this->assertTrue($office->fresh()->is_default);
        $this->assertSame(1, $this->user->addresses()->where('is_default', true)->count());
    }

    public function test_removing_the_default_promotes_another(): void
    {
        $home = $this->address();
        $office = $this->address(['label' => 'Office', 'is_default' => false]);

        $this->actingAs($this->user)->delete(route('addresses.destroy', $home));

        // Checkout must always have one to reach for.
        $this->assertTrue($office->fresh()->is_default);
    }

    public function test_one_customer_cannot_touch_anothers_address(): void
    {
        $address = $this->address();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->delete(route('addresses.destroy', $address))->assertForbidden();
        $this->actingAs($stranger)->patch(route('addresses.default', $address))->assertForbidden();

        $this->assertDatabaseHas('addresses', ['id' => $address->id]);
    }

    public function test_the_addresses_tab_lists_them(): void
    {
        $this->address(['label' => 'Office', 'address' => 'Norodom Blvd']);

        $this->actingAs($this->user)
            ->get('/dashboard?tab=addresses')
            ->assertOk()
            ->assertSee('Office')
            ->assertSee('Norodom Blvd');
    }
}
