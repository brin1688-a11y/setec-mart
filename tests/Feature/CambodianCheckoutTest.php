<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Support\Cambodia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CambodianCheckoutTest extends TestCase
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
            'price' => 5.00,
            'stock' => 50,
            'status' => true,
        ]);

        $cart = Cart::create(['user_id' => $this->user->id]);
        $cart->items()->create(['product_id' => $this->product->id, 'quantity' => 2]); // $10.00
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Sok Dara',
            'phone' => '012 345 678',
            'province' => 'Phnom Penh',
            'district' => 'Chamkar Mon',
            'commune' => 'Tonle Bassac',
            'address' => 'Phum 4, St. 271, House 12B',
            'telegram' => '@sokdara',
            'payment_method' => 'cod',
        ], $overrides);
    }

    // ---- Delivery pricing ------------------------------------------------

    public function test_phnom_penh_under_the_threshold_pays_the_city_rate(): void
    {
        // $10 is below the $20 free-delivery threshold.
        $this->actingAs($this->user)->post('/checkout', $this->payload())->assertRedirect();

        $order = Order::first();

        $this->assertSame('10.00', $order->subtotal);
        $this->assertSame('1.50', $order->delivery_fee);
        $this->assertSame('11.50', $order->total);
    }

    public function test_phnom_penh_ships_free_over_twenty_dollars(): void
    {
        $this->user->cart->items()->first()->update(['quantity' => 5]); // $25

        $this->actingAs($this->user)->post('/checkout', $this->payload());

        $order = Order::first();

        $this->assertSame('25.00', $order->subtotal);
        $this->assertSame('0.00', $order->delivery_fee);
        $this->assertSame('25.00', $order->total);
    }

    public function test_delivery_outside_phnom_penh_costs_two_fifty(): void
    {
        $this->actingAs($this->user)
            ->post('/checkout', $this->payload(['province' => 'Siem Reap', 'district' => 'Siem Reap', 'commune' => 'Svay Dangkum']));

        $order = Order::first();

        $this->assertSame('2.50', $order->delivery_fee);
        $this->assertSame('12.50', $order->total);
    }

    public function test_the_provinces_never_ship_free_however_large_the_order(): void
    {
        // Free delivery is a Phnom Penh offer only.
        $this->user->cart->items()->first()->update(['quantity' => 20]); // $100

        $this->actingAs($this->user)
            ->post('/checkout', $this->payload(['province' => 'Siem Reap']));

        $order = Order::first();

        $this->assertSame('100.00', $order->subtotal);
        $this->assertSame('2.50', $order->delivery_fee);
        $this->assertSame('102.50', $order->total);
    }

    public function test_the_delivery_fee_cannot_be_forged_through_the_form(): void
    {
        $this->actingAs($this->user)
            ->post('/checkout', $this->payload([
                'delivery_fee' => 0,
                'subtotal' => 1,
                'total' => 1,
            ]));

        $order = Order::first();

        // Priced from the province on the server, not from what was posted.
        $this->assertSame('1.50', $order->delivery_fee);
        $this->assertSame('11.50', $order->total);
    }

    public function test_the_khqr_payment_is_charged_the_total_including_delivery(): void
    {
        config(['services.cutluy.key' => 'ck_test_key']);

        Http::fake(['cutluy.com/v1/payments' => Http::response([
            'id' => 'PAY1', 'status' => 'pending', 'amount' => '12.50',
            'qr_string' => '0002010102122', 'checkout_url' => 'https://cutluy.com/pay/PAY1',
        ], 201)]);

        // A province, so $2.50 delivery on top of the $10 subtotal.
        $this->actingAs($this->user)->post('/checkout', $this->payload([
            'payment_method' => 'khqr',
            'province' => 'Kandal',
        ]));

        Http::assertSent(fn ($request) => $request['amount'] === 12.5);
    }

    // ---- Address & phone -------------------------------------------------

    public function test_the_structured_address_is_stored(): void
    {
        $this->actingAs($this->user)->post('/checkout', $this->payload());

        $order = Order::first();

        $this->assertSame('Phnom Penh', $order->province);
        $this->assertSame('Chamkar Mon', $order->district);
        $this->assertSame('Tonle Bassac', $order->commune);
        $this->assertSame(
            'Phum 4, St. 271, House 12B, Tonle Bassac, Chamkar Mon, Phnom Penh',
            $order->fullAddress()
        );
    }

    public function test_a_phone_number_is_stored_in_one_canonical_shape(): void
    {
        $this->actingAs($this->user)->post('/checkout', $this->payload(['phone' => '+855 12 345 678']));

        $this->assertSame('012345678', Order::first()->phone);
        $this->assertSame('012 345 678', Order::first()->formattedPhone());
    }

    public function test_a_non_cambodian_phone_number_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->post('/checkout', $this->payload(['phone' => '+1 555 0100']))
            ->assertSessionHasErrors('phone');

        $this->assertSame(0, Order::count());
    }

    public function test_a_made_up_province_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->post('/checkout', $this->payload(['province' => 'Atlantis']))
            ->assertSessionHasErrors('province');

        $this->assertSame(0, Order::count());
    }

    public function test_district_and_commune_are_required(): void
    {
        $this->actingAs($this->user)
            ->post('/checkout', $this->payload(['district' => '', 'commune' => '']))
            ->assertSessionHasErrors(['district', 'commune']);
    }

    public function test_the_telegram_handle_is_stored_without_the_at_sign(): void
    {
        $this->actingAs($this->user)->post('/checkout', $this->payload(['telegram' => 'https://t.me/sokdara']));

        $this->assertSame('sokdara', Order::first()->telegram);
    }

    public function test_telegram_is_optional(): void
    {
        $this->actingAs($this->user)
            ->post('/checkout', $this->payload(['telegram' => null]))
            ->assertSessionHasNoErrors();

        $this->assertNull(Order::first()->telegram);
    }

    // ---- Saved details ---------------------------------------------------

    public function test_ticking_save_stores_the_address_on_the_account(): void
    {
        $this->actingAs($this->user)->post('/checkout', $this->payload(['save_address' => 1]));

        $this->user->refresh();

        $this->assertSame('Phnom Penh', $this->user->province);
        $this->assertSame('012345678', $this->user->phone);
        $this->assertSame('sokdara', $this->user->telegram);
        $this->assertTrue($this->user->hasSavedAddress());
    }

    public function test_leaving_save_unticked_does_not_change_the_account(): void
    {
        $this->actingAs($this->user)->post('/checkout', $this->payload());

        $this->assertNull($this->user->fresh()->province);
    }

    public function test_checkout_prefills_from_saved_details(): void
    {
        $this->user->update([
            'phone' => '012345678',
            'province' => 'Kandal',
            'district' => 'Ta Khmau',
            'commune' => 'Ta Khmau',
            'address' => 'Phum 3',
        ]);

        $this->actingAs($this->user)
            ->get('/checkout')
            ->assertOk()
            ->assertSee('Ta Khmau')
            ->assertSee('Phum 3');
    }

    // ---- Account settings -------------------------------------------------

    public function test_delivery_details_can_be_saved_from_the_profile(): void
    {
        $this->actingAs($this->user)
            ->put('/profile/delivery', [
                'phone' => '+855 77 888 999',
                'telegram' => '@dara_k',
                'province' => 'Battambang',
                'district' => 'Battambang',
                'commune' => 'Svay Por',
                'address' => 'Phum 1, St. 3',
            ])
            ->assertSessionHasNoErrors();

        $this->user->refresh();

        $this->assertSame('077888999', $this->user->phone);
        $this->assertSame('dara_k', $this->user->telegram);
        $this->assertSame('Battambang', $this->user->province);
    }

    public function test_the_profile_rejects_a_bad_phone_number(): void
    {
        $this->actingAs($this->user)
            ->put('/profile/delivery', ['phone' => '123'])
            ->assertSessionHasErrors('phone');
    }

    public function test_name_and_email_can_be_updated(): void
    {
        $this->actingAs($this->user)
            ->put('/profile/account', ['name' => 'New Name', 'email' => 'new@example.com'])
            ->assertSessionHasNoErrors();

        $this->assertSame('New Name', $this->user->fresh()->name);
        $this->assertSame('new@example.com', $this->user->fresh()->email);
    }

    public function test_an_email_already_taken_is_rejected(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($this->user)
            ->put('/profile/account', ['name' => 'X', 'email' => 'taken@example.com'])
            ->assertSessionHasErrors('email');
    }

    // ---- The helper itself -------------------------------------------------

    public function test_an_unknown_province_is_never_priced_as_free(): void
    {
        // An unrecognised province must not accidentally get Phnom Penh's
        // rate or its free threshold; it falls back to the province rate.
        $this->assertSame(2.50, Cambodia::deliveryFee('Nowhere', 0));
        $this->assertSame(2.50, Cambodia::deliveryFee('Nowhere', 1000));
    }

    public function test_the_delivery_time_is_quoted_per_zone(): void
    {
        $this->assertSame('Same day, 2-4 hours', Cambodia::deliveryEta('Phnom Penh'));
        $this->assertSame('1-2 days', Cambodia::deliveryEta('Kandal'));
        $this->assertSame('2-3 days', Cambodia::deliveryEta('Siem Reap'));

        // Phnom Penh arrives the same day; Siem Reap three days out.
        $this->assertSame(
            now()->toDateString(),
            Cambodia::estimatedArrival('Phnom Penh')->toDateString()
        );
        $this->assertSame(
            now()->addDays(3)->toDateString(),
            Cambodia::estimatedArrival('Siem Reap')->toDateString()
        );
    }

    public function test_only_phnom_penh_advertises_free_delivery(): void
    {
        $this->assertSame(20.00, Cambodia::shipsFreeAt('Phnom Penh'));
        $this->assertNull(Cambodia::shipsFreeAt('Siem Reap'));
        $this->assertNull(Cambodia::shipsFreeAt('Kandal'));
    }
}
