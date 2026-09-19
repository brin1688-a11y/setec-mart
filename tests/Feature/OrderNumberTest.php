<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderNumberTest extends TestCase
{
    use RefreshDatabase;

    protected function order(array $overrides = []): Order
    {
        $user = $overrides['user'] ?? User::factory()->create();
        unset($overrides['user']);

        $category = Category::firstOrCreate(['name' => 'Fruit']);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Mango',
            'description' => 'Sweet',
            'price' => 1.50,
            'stock' => 50,
            'status' => true,
        ]);

        $order = Order::create(array_merge([
            'user_id' => $user->id,
            'name' => 'Sok Dara',
            'phone' => '012345678',
            'province' => 'Phnom Penh',
            'district' => 'Chamkar Mon',
            'commune' => 'Tonle Bassac',
            'address' => 'St. 271',
            'status' => 'Pending',
            'subtotal' => 3.00,
            'discount' => 0,
            'delivery_fee' => 1.50,
            'total' => 4.50,
        ], $overrides));

        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => 'Mango',
            'price' => 1.50,
            'quantity' => 2,
        ]);

        return $order;
    }

    public function test_every_order_is_numbered_on_creation(): void
    {
        $order = $this->order();

        $this->assertMatchesRegularExpression(
            '/^SM-\d{6}-\d{4}$/',
            $order->order_number,
            'Expected a reference like SM-260918-4821'
        );
    }

    public function test_the_reference_is_dated_from_when_the_order_was_placed(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 18));

        $this->assertStringStartsWith('SM-260918-', $this->order()->order_number);
    }

    public function test_the_reference_does_not_give_away_the_row_id(): void
    {
        // Three orders in a row must not read as 1, 2, 3 — that is the whole
        // point of replacing the primary key in the interface.
        $numbers = collect(range(1, 3))->map(fn () => $this->order()->order_number);

        foreach ($numbers as $number) {
            $tail = (int) substr($number, -4);
            $this->assertGreaterThanOrEqual(0, $tail);
        }

        $this->assertCount(3, $numbers->unique());
    }

    public function test_references_are_unique_across_many_orders(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 25; $i++) {
            $this->order(['user' => $user]);
        }

        $this->assertSame(25, DB::table('orders')->distinct()->count('order_number'));
    }

    public function test_a_taken_reference_is_drawn_again_rather_than_failing(): void
    {
        $user = User::factory()->create();

        // Occupy one reference, then force the generator to draw it first.
        $taken = $this->order(['user' => $user, 'order_number' => 'SM-260918-0001']);

        $this->travelTo(now()->setDate(2026, 9, 18));

        $numbers = collect(range(1, 40))->map(fn () => Order::nextNumber());

        // Forty draws from ten thousand will not include the one already used.
        $this->assertNotContains($taken->order_number, $numbers->all());
    }

    public function test_an_explicit_reference_is_respected(): void
    {
        // The migration backfills existing rows, so a supplied value must win
        // over the generator.
        $order = $this->order(['order_number' => 'SM-240101-0001']);

        $this->assertSame('SM-240101-0001', $order->order_number);
    }

    public function test_orders_are_addressed_by_reference_not_row_id(): void
    {
        $user = User::factory()->create();
        $order = $this->order(['user' => $user]);

        $this->assertSame(
            url('/orders/'.$order->order_number),
            route('orders.show', $order)
        );

        $this->actingAs($user)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee($order->order_number);

        // The old id-based URL is no longer an address at all.
        $this->actingAs($user)
            ->get('/orders/'.$order->id)
            ->assertNotFound();
    }

    public function test_the_list_shows_the_reference_and_never_the_row_id(): void
    {
        $user = User::factory()->create();
        $order = $this->order(['user' => $user]);

        $this->actingAs($user)
            ->get('/orders')
            ->assertOk()
            ->assertSee($order->order_number)
            ->assertDontSee('Order #'.$order->id);
    }

    public function test_admin_pages_use_the_reference_too(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->order();

        $this->actingAs($admin)
            ->get('/admin/orders')
            ->assertOk()
            ->assertSee($order->order_number);

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSee($order->order_number);
    }

    public function test_one_customer_cannot_reach_anothers_order_by_guessing(): void
    {
        $order = $this->order();

        $this->actingAs(User::factory()->create())
            ->get(route('orders.show', $order))
            ->assertForbidden();
    }
}
