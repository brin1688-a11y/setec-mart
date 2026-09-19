<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Cambodia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliverySettingsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::flushCache();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    /**
     * @param  array<string, array<string, mixed>>  $overrides
     */
    protected function save(array $overrides): void
    {
        $zones = [];

        foreach (config('cambodia.delivery.zones') as $key => $zone) {
            $zones[$key] = array_merge([
                'fee' => $zone['fee'],
                'free_over' => $zone['free_over'],
                'eta' => $zone['eta'],
                'eta_days' => $zone['eta_days'],
            ], $overrides[$key] ?? []);
        }

        $this->actingAs($this->admin)
            ->put(route('admin.delivery.update'), ['zones' => $zones])
            ->assertSessionHasNoErrors();
    }

    // ---- Who may change them ---------------------------------------------

    public function test_a_customer_cannot_open_or_change_them(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);

        $this->actingAs($customer)->get(route('admin.delivery.edit'))->assertForbidden();
        $this->actingAs($customer)->put(route('admin.delivery.update'), ['zones' => []])->assertForbidden();
    }

    public function test_an_admin_sees_the_current_charges(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.delivery.edit'))
            ->assertOk()
            ->assertSee('Phnom Penh')
            ->assertSee('Delivery charges');
    }

    // ---- Saving -----------------------------------------------------------

    public function test_a_new_price_takes_effect_everywhere_at_once(): void
    {
        $this->assertSame(1.50, Cambodia::deliveryFee('Phnom Penh', 5));

        $this->save(['city' => ['fee' => 2.75, 'free_over' => 40]]);

        $this->assertSame(2.75, Cambodia::deliveryFee('Phnom Penh', 5));
        $this->assertSame(0.0, Cambodia::deliveryFee('Phnom Penh', 40));
        $this->assertSame(40.0, Cambodia::shipsFreeAt('Phnom Penh'));
    }

    public function test_an_empty_free_threshold_means_never_free(): void
    {
        // Saving it as 0 would make everything free, which is not what an
        // empty box means.
        $this->save(['city' => ['fee' => 2.00, 'free_over' => '']]);

        $this->assertNull(Cambodia::shipsFreeAt('Phnom Penh'));
        $this->assertSame(2.00, Cambodia::deliveryFee('Phnom Penh', 9999));
    }

    public function test_the_lead_time_is_saved_and_shown(): void
    {
        $this->save(['far' => ['eta' => 'Within 5 days', 'eta_days' => 5]]);

        $this->assertSame('Within 5 days', Cambodia::deliveryEta('Siem Reap'));
        $this->assertSame(
            now()->addDays(5)->toDateString(),
            Cambodia::estimatedArrival('Siem Reap')->toDateString()
        );
    }

    public function test_a_negative_price_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.delivery.update'), [
                'zones' => ['city' => ['fee' => -5, 'eta' => 'Same day', 'eta_days' => 0]],
            ])
            ->assertSessionHasErrors('zones.city.fee');

        $this->assertSame(1.50, Cambodia::deliveryFee('Phnom Penh', 5));
    }

    public function test_a_missing_lead_time_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.delivery.update'), [
                'zones' => ['city' => ['fee' => 2, 'eta' => '', 'eta_days' => 0]],
            ])
            ->assertSessionHasErrors('zones.city.eta');
    }

    public function test_the_defaults_can_be_restored(): void
    {
        $this->save(['city' => ['fee' => 9.99]]);
        $this->assertSame(9.99, Cambodia::deliveryFee('Phnom Penh', 5));

        $this->actingAs($this->admin)->delete(route('admin.delivery.reset'));

        $this->assertSame(1.50, Cambodia::deliveryFee('Phnom Penh', 5));
    }

    public function test_with_nothing_saved_the_config_file_is_used(): void
    {
        // A fresh install has no settings row and must still price delivery.
        $this->assertSame(0, Setting::query()->count());
        $this->assertSame(1.50, Cambodia::deliveryFee('Phnom Penh', 5));
        $this->assertSame(2.50, Cambodia::deliveryFee('Siem Reap', 5));
    }

    // ---- What the change actually reaches ---------------------------------

    public function test_checkout_charges_the_new_price(): void
    {
        $this->save(['city' => ['fee' => 3.00, 'free_over' => null]]);

        $customer = User::factory()->create(['role' => 'customer']);
        $category = Category::create(['name' => 'Grocery']);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Rice',
            'description' => 'Jasmine',
            'price' => 5.00,
            'stock' => 20,
            'status' => true,
        ]);

        $cart = Cart::create(['user_id' => $customer->id]);
        $cart->items()->create(['product_id' => $product->id, 'quantity' => 2]);

        $this->actingAs($customer)->post('/checkout', [
            'name' => 'Sok Dara',
            'phone' => '012345678',
            'province' => 'Phnom Penh',
            'district' => 'Chamkar Mon',
            'commune' => 'Tonle Bassac',
            'address' => 'St. 271',
            'payment_method' => 'cod',
        ])->assertRedirect();

        $order = Order::first();

        $this->assertSame('3.00', $order->delivery_fee);
        $this->assertSame('13.00', $order->total);
    }

    public function test_an_order_already_placed_keeps_the_price_it_was_quoted(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);

        $order = Order::create([
            'user_id' => $customer->id,
            'name' => 'Sok Dara',
            'phone' => '012345678',
            'province' => 'Phnom Penh',
            'district' => 'Chamkar Mon',
            'commune' => 'Tonle Bassac',
            'address' => 'St. 271',
            'status' => 'Confirmed',
            'subtotal' => 10.00,
            'discount' => 0,
            'delivery_fee' => 1.50,
            'total' => 11.50,
        ]);

        $this->save(['city' => ['fee' => 8.00]]);

        // Re-pricing history would rewrite somebody's receipt.
        $this->assertSame('1.50', $order->fresh()->delivery_fee);
        $this->assertSame('11.50', $order->fresh()->total);
    }

    public function test_the_storefront_footer_quotes_the_new_price(): void
    {
        $this->save(['city' => ['fee' => 4.25]]);

        $this->get('/')->assertOk()->assertSee('$4.25');
    }
}
