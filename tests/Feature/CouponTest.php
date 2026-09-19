<?php

namespace Tests\Feature;

use App\Http\Controllers\CouponController;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouponTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'customer']);

        $category = Category::create(['name' => 'Dairy']);

        $this->product = Product::create([
            'category_id' => $category->id,
            'name' => 'Fresh Milk',
            'description' => 'Cold',
            'price' => 10.00,
            'stock' => 50,
            'status' => true,
        ]);

        $cart = Cart::create(['user_id' => $this->user->id]);
        $cart->items()->create(['product_id' => $this->product->id, 'quantity' => 2]); // $20.00
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function order(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Sok',
            'phone' => '012345678',
            'province' => 'Phnom Penh',
            'district' => 'Chamkar Mon',
            'commune' => 'Tonle Bassac',
            'address' => 'Phum 4',
            'payment_method' => 'cod',
        ], $overrides);
    }

    // ---- Delivery pricing (changed) --------------------------------------

    public function test_phnom_penh_delivery_is_now_free(): void
    {
        $this->actingAs($this->user)->post('/checkout', $this->order());

        $o = Order::first();
        $this->assertSame('0.00', $o->delivery_fee);
        $this->assertSame('20.00', $o->total);
    }

    public function test_provinces_cost_two_fifty(): void
    {
        $this->actingAs($this->user)
            ->post('/checkout', $this->order(['province' => 'Siem Reap']));

        $this->assertSame('2.50', Order::first()->delivery_fee);
    }

    // ---- Applying ---------------------------------------------------------

    public function test_a_percentage_coupon_comes_off_the_subtotal(): void
    {
        Coupon::create(['code' => 'SAVE10', 'type' => 'percent', 'value' => 10]);

        $this->actingAs($this->user)->post('/checkout/coupon', ['code' => 'SAVE10']);

        $this->actingAs($this->user)->post('/checkout', $this->order());

        $o = Order::first();
        $this->assertSame('SAVE10', $o->coupon_code);
        $this->assertSame('2.00', $o->discount);
        $this->assertSame('18.00', $o->total);   // 20 - 2 + 0 delivery
    }

    public function test_a_fixed_coupon_comes_off_the_subtotal(): void
    {
        Coupon::create(['code' => 'FIVE', 'type' => 'fixed', 'value' => 5]);

        $this->actingAs($this->user)->post('/checkout/coupon', ['code' => 'FIVE']);
        $this->actingAs($this->user)->post('/checkout', $this->order(['province' => 'Kandal']));

        $o = Order::first();
        $this->assertSame('5.00', $o->discount);
        $this->assertSame('17.50', $o->total);   // 20 - 5 + 2.50 delivery
    }

    public function test_codes_are_not_case_sensitive(): void
    {
        Coupon::create(['code' => 'SAVE10', 'type' => 'percent', 'value' => 10]);

        $this->actingAs($this->user)
            ->post('/checkout/coupon', ['code' => 'save10'])
            ->assertSessionHas('coupon_success');
    }

    public function test_a_percentage_cap_is_respected(): void
    {
        Coupon::create(['code' => 'HALF', 'type' => 'percent', 'value' => 50, 'max_discount' => 3]);

        $this->actingAs($this->user)->post('/checkout/coupon', ['code' => 'HALF']);
        $this->actingAs($this->user)->post('/checkout', $this->order());

        // 50% of $20 is $10, but the cap is $3.
        $this->assertSame('3.00', Order::first()->discount);
    }

    public function test_a_discount_can_never_exceed_the_subtotal(): void
    {
        $coupon = Coupon::create(['code' => 'HUGE', 'type' => 'fixed', 'value' => 500]);

        $this->assertSame(20.00, $coupon->discountFor(20.00));
    }

    // ---- Rejections -------------------------------------------------------

    public function test_an_unknown_code_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->post('/checkout/coupon', ['code' => 'NOPE'])
            ->assertSessionHas('coupon_error');
    }

    public function test_an_expired_code_is_rejected(): void
    {
        Coupon::create(['code' => 'OLD', 'type' => 'percent', 'value' => 10, 'expires_at' => now()->subDay()]);

        $this->actingAs($this->user)
            ->post('/checkout/coupon', ['code' => 'OLD'])
            ->assertSessionHas('coupon_error');
    }

    public function test_a_disabled_code_is_rejected(): void
    {
        Coupon::create(['code' => 'OFF', 'type' => 'percent', 'value' => 10, 'active' => false]);

        $this->actingAs($this->user)
            ->post('/checkout/coupon', ['code' => 'OFF'])
            ->assertSessionHas('coupon_error');
    }

    public function test_a_code_below_its_minimum_is_rejected(): void
    {
        Coupon::create(['code' => 'BIG', 'type' => 'fixed', 'value' => 5, 'min_subtotal' => 100]);

        $this->actingAs($this->user)
            ->post('/checkout/coupon', ['code' => 'BIG'])
            ->assertSessionHas('coupon_error');
    }

    public function test_a_used_up_code_is_rejected(): void
    {
        $c = Coupon::create(['code' => 'ONCE', 'type' => 'percent', 'value' => 10, 'max_uses' => 1]);
        $c->forceFill(['used_count' => 1])->save();

        $this->actingAs($this->user)
            ->post('/checkout/coupon', ['code' => 'ONCE'])
            ->assertSessionHas('coupon_error');
    }

    public function test_a_code_that_expires_between_applying_and_ordering_is_dropped(): void
    {
        $c = Coupon::create(['code' => 'SAVE10', 'type' => 'percent', 'value' => 10]);

        $this->actingAs($this->user)->post('/checkout/coupon', ['code' => 'SAVE10']);

        // Admin disables it while the customer is still filling the form.
        $c->update(['active' => false]);

        $this->actingAs($this->user)->post('/checkout', $this->order());

        $o = Order::first();
        $this->assertNull($o->coupon_code);
        $this->assertSame('0.00', $o->discount);
        $this->assertSame('20.00', $o->total);
    }

    // ---- Bookkeeping ------------------------------------------------------

    public function test_placing_an_order_counts_the_redemption(): void
    {
        $c = Coupon::create(['code' => 'SAVE10', 'type' => 'percent', 'value' => 10]);

        $this->actingAs($this->user)->post('/checkout/coupon', ['code' => 'SAVE10']);
        $this->actingAs($this->user)->post('/checkout', $this->order());

        $this->assertSame(1, $c->fresh()->used_count);
    }

    public function test_the_coupon_is_cleared_once_the_order_is_placed(): void
    {
        Coupon::create(['code' => 'SAVE10', 'type' => 'percent', 'value' => 10]);

        $this->actingAs($this->user)->post('/checkout/coupon', ['code' => 'SAVE10']);
        $this->actingAs($this->user)->post('/checkout', $this->order());

        $this->assertNull(session(CouponController::SESSION_KEY));
    }

    public function test_a_coupon_can_be_removed(): void
    {
        Coupon::create(['code' => 'SAVE10', 'type' => 'percent', 'value' => 10]);

        $this->actingAs($this->user)->post('/checkout/coupon', ['code' => 'SAVE10']);
        $this->actingAs($this->user)->delete('/checkout/coupon');

        $this->assertNull(session(CouponController::SESSION_KEY));
    }

    // ---- Scoped to a category or product ----------------------------------

    /**
     * Adds a second product in another category, so the cart has both an
     * eligible and an ineligible line.
     */
    protected function addSnack(float $price = 4.00, int $qty = 1): Product
    {
        $snacks = Category::create(['name' => 'Snacks']);

        $snack = Product::create([
            'category_id' => $snacks->id,
            'name' => 'Chips',
            'description' => 'Crunchy',
            'price' => $price,
            'stock' => 20,
            'status' => true,
        ]);

        $this->user->cart->items()->create(['product_id' => $snack->id, 'quantity' => $qty]);

        return $snack;
    }

    public function test_a_category_coupon_only_discounts_that_category(): void
    {
        $snack = $this->addSnack(4.00);          // cart is now $20 dairy + $4 snacks

        $coupon = Coupon::create(['code' => 'DAIRY10', 'type' => 'percent', 'value' => 10]);
        $coupon->categories()->attach($this->product->category_id);

        $this->actingAs($this->user)->post('/checkout/coupon', ['code' => 'DAIRY10']);
        $this->actingAs($this->user)->post('/checkout', $this->order());

        $o = Order::first();

        // 10% of the $20 dairy line only — not of the $24 cart.
        $this->assertSame('2.00', $o->discount);
        $this->assertSame('22.00', $o->total);
    }

    public function test_a_product_coupon_only_discounts_that_product(): void
    {
        $snack = $this->addSnack(10.00);

        $coupon = Coupon::create(['code' => 'CHIPS', 'type' => 'percent', 'value' => 50]);
        $coupon->products()->attach($snack->id);

        $this->actingAs($this->user)->post('/checkout/coupon', ['code' => 'CHIPS']);
        $this->actingAs($this->user)->post('/checkout', $this->order());

        // 50% of the $10 chips line.
        $this->assertSame('5.00', Order::first()->discount);
    }

    public function test_a_coupon_with_no_scope_still_discounts_everything(): void
    {
        $this->addSnack(4.00);

        Coupon::create(['code' => 'ALL10', 'type' => 'percent', 'value' => 10]);

        $this->actingAs($this->user)->post('/checkout/coupon', ['code' => 'ALL10']);
        $this->actingAs($this->user)->post('/checkout', $this->order());

        // 10% of the whole $24 cart.
        $this->assertSame('2.40', Order::first()->discount);
    }

    public function test_a_coupon_for_a_category_not_in_the_cart_is_refused(): void
    {
        $other = Category::create(['name' => 'Frozen']);

        $coupon = Coupon::create(['code' => 'FROZEN', 'type' => 'percent', 'value' => 20]);
        $coupon->categories()->attach($other->id);

        $this->actingAs($this->user)
            ->post('/checkout/coupon', ['code' => 'FROZEN'])
            ->assertSessionHas('coupon_error');

        $this->assertNull(session(CouponController::SESSION_KEY));
    }

    public function test_a_scoped_coupon_is_dropped_if_its_items_leave_the_cart(): void
    {
        $snack = $this->addSnack(10.00);

        $coupon = Coupon::create(['code' => 'CHIPS', 'type' => 'fixed', 'value' => 5]);
        $coupon->products()->attach($snack->id);

        $this->actingAs($this->user)->post('/checkout/coupon', ['code' => 'CHIPS']);

        // The customer removes the chips before finishing checkout.
        $this->user->cart->items()->where('product_id', $snack->id)->delete();

        $this->actingAs($this->user)->post('/checkout', $this->order());

        $o = Order::first();
        $this->assertNull($o->coupon_code);
        $this->assertSame('0.00', $o->discount);
    }

    public function test_both_a_category_and_a_product_can_be_attached(): void
    {
        $snack = $this->addSnack(4.00);

        $coupon = Coupon::create(['code' => 'MIX', 'type' => 'percent', 'value' => 10]);
        $coupon->categories()->attach($this->product->category_id);
        $coupon->products()->attach($snack->id);

        $this->actingAs($this->user)->post('/checkout/coupon', ['code' => 'MIX']);
        $this->actingAs($this->user)->post('/checkout', $this->order());

        // Both lines are covered: 10% of $24.
        $this->assertSame('2.40', Order::first()->discount);
    }

    public function test_an_admin_can_scope_a_coupon_when_creating_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post('/admin/coupons', [
            'code' => 'SCOPED',
            'type' => 'percent',
            'value' => 10,
            'categories' => [$this->product->category_id],
            'products' => [$this->product->id],
        ])->assertRedirect();

        $coupon = Coupon::where('code', 'SCOPED')->first();

        $this->assertTrue($coupon->categories->contains('id', $this->product->category_id));
        $this->assertTrue($coupon->products->contains('id', $this->product->id));
        $this->assertFalse($coupon->appliesToEverything());
    }

    public function test_clearing_the_scope_makes_a_coupon_apply_to_everything_again(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $coupon = Coupon::create(['code' => 'SCOPED', 'type' => 'percent', 'value' => 10]);
        $coupon->categories()->attach($this->product->category_id);

        $this->actingAs($admin)->put("/admin/coupons/{$coupon->id}", [
            'code' => 'SCOPED', 'type' => 'percent', 'value' => 10,
        ]);

        $this->assertTrue($coupon->fresh()->appliesToEverything());
    }

    // ---- Admin -------------------------------------------------------------

    public function test_a_customer_cannot_reach_coupon_admin(): void
    {
        $this->actingAs($this->user)->get('/admin/coupons')->assertForbidden();
    }

    public function test_an_admin_can_create_a_coupon(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post('/admin/coupons', [
            'code' => 'newyear',
            'type' => 'percent',
            'value' => 15,
            'active' => 1,
        ])->assertRedirect(route('admin.coupons.index'));

        // Stored upper-cased so customers can type it any way.
        $this->assertNotNull(Coupon::where('code', 'NEWYEAR')->first());
    }

    public function test_the_form_can_be_submitted_with_every_optional_field_blank(): void
    {
        // The browser posts every field, so the optional ones arrive as empty
        // strings rather than being absent. min_subtotal is NOT NULL, so an
        // empty one has to become 0 instead of null.
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post('/admin/coupons', [
            'code' => 'KNY',
            'description' => 'New year promotion',
            'type' => 'percent',
            'value' => '50',
            'min_subtotal' => '',
            'max_discount' => '',
            'max_uses' => '',
            'starts_at' => '',
            'expires_at' => '',
            'active' => '1',
        ])->assertRedirect(route('admin.coupons.index'))
          ->assertSessionHasNoErrors();

        $coupon = Coupon::where('code', 'KNY')->first();

        $this->assertNotNull($coupon);
        $this->assertSame('0.00', $coupon->min_subtotal);
        $this->assertNull($coupon->max_discount);
        $this->assertNull($coupon->max_uses);
        $this->assertNull($coupon->starts_at);
        $this->assertNull($coupon->expires_at);
        $this->assertTrue($coupon->active);

        // And it still works at checkout.
        $this->actingAs($this->user)
            ->post('/checkout/coupon', ['code' => 'KNY'])
            ->assertSessionHas('coupon_success');
    }

    public function test_an_existing_coupon_can_be_edited_with_blank_optionals(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $coupon = Coupon::create([
            'code' => 'EDITME', 'type' => 'percent', 'value' => 10, 'min_subtotal' => 25,
        ]);

        $this->actingAs($admin)->put("/admin/coupons/{$coupon->id}", [
            'code' => 'EDITME',
            'type' => 'percent',
            'value' => '10',
            'min_subtotal' => '',
            'max_discount' => '',
            'max_uses' => '',
            'starts_at' => '',
            'expires_at' => '',
        ])->assertSessionHasNoErrors();

        // Clearing the minimum resets it to zero, not null.
        $this->assertSame('0.00', $coupon->fresh()->min_subtotal);
    }

    public function test_a_percentage_over_one_hundred_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post('/admin/coupons', [
            'code' => 'TOOMUCH', 'type' => 'percent', 'value' => 150,
        ])->assertSessionHasErrors('value');
    }

    public function test_a_duplicate_code_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Coupon::create(['code' => 'TAKEN', 'type' => 'percent', 'value' => 10]);

        $this->actingAs($admin)->post('/admin/coupons', [
            'code' => 'taken', 'type' => 'percent', 'value' => 10,
        ])->assertSessionHasErrors('code');
    }

    public function test_an_admin_can_disable_a_coupon(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $c = Coupon::create(['code' => 'SAVE10', 'type' => 'percent', 'value' => 10]);

        $this->actingAs($admin)->patch("/admin/coupons/{$c->id}/toggle");

        $this->assertFalse($c->fresh()->active);
    }

    public function test_a_used_coupon_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $c = Coupon::create(['code' => 'USED', 'type' => 'percent', 'value' => 10]);
        $c->forceFill(['used_count' => 3])->save();

        $this->actingAs($admin)->delete("/admin/coupons/{$c->id}")->assertSessionHas('error');

        $this->assertNotNull($c->fresh());
    }

    public function test_an_unused_coupon_can_be_deleted(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $c = Coupon::create(['code' => 'UNUSED', 'type' => 'percent', 'value' => 10]);

        $this->actingAs($admin)->delete("/admin/coupons/{$c->id}");

        $this->assertNull(Coupon::find($c->id));
    }

    public function test_the_admin_list_shows_what_each_code_gave_away(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Coupon::create(['code' => 'SAVE10', 'type' => 'percent', 'value' => 10]);

        $this->actingAs($this->user)->post('/checkout/coupon', ['code' => 'SAVE10']);
        $this->actingAs($this->user)->post('/checkout', $this->order());

        $this->actingAs($admin)->get('/admin/coupons')
            ->assertOk()
            ->assertSee('SAVE10')
            ->assertSee('$2.00');
    }
}
