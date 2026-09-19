<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminCustomersPageTest extends TestCase
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
            'stock' => 100,
            'status' => true,
        ]);
    }

    protected function customer(array $overrides = []): User
    {
        return User::factory()->create(array_merge(['role' => 'customer'], $overrides));
    }

    protected function order(User $user, float $total, string $status = 'Delivered', ?string $at = null): Order
    {
        $order = Order::create([
            'user_id' => $user->id,
            'name' => $user->name,
            'phone' => '012345678',
            'province' => 'Phnom Penh',
            'district' => 'Chamkar Mon',
            'commune' => 'Tonle Bassac',
            'address' => 'St. 271',
            'status' => $status,
            'subtotal' => $total,
            'discount' => 0,
            'delivery_fee' => 0,
            'total' => $total,
        ]);

        $order->items()->create([
            'product_id' => $this->product->id,
            'product_name' => 'Kampot Pepper',
            'price' => 3.00,
            'quantity' => 2,
        ]);

        if ($at) {
            DB::table('orders')->where('id', $order->id)->update(['created_at' => $at]);
        }

        return $order->fresh();
    }

    public function test_a_customer_cannot_open_it(): void
    {
        $this->actingAs($this->customer())->get('/admin/customers')->assertForbidden();
    }

    public function test_it_shows_spend_and_order_count_per_customer(): void
    {
        $buyer = $this->customer(['name' => 'Chan Sophea', 'phone' => '077888999']);

        $this->order($buyer, 12.00);
        $this->order($buyer, 8.00);
        // A cancelled order was never paid for, so it earns no spend.
        $this->order($buyer, 99.00, status: 'Cancelled');

        $page = $this->actingAs($this->admin)->get('/admin/customers')->assertOk();

        $row = $page->viewData('customers')->firstWhere('id', $buyer->id);

        $this->assertSame(3, $row->orders_count);
        $this->assertSame(20.0, (float) $row->spend);

        $page->assertSee('Chan Sophea')->assertSee('077 888 999');
    }

    public function test_customers_can_be_found_by_name_email_or_phone(): void
    {
        $wanted = $this->customer(['name' => 'Chan Sophea', 'email' => 'sophea@example.com', 'phone' => '077888999']);
        $this->customer(['name' => 'Someone Else', 'email' => 'other@example.com', 'phone' => '012000111']);

        foreach (['sophea', 'sophea@example', '077888999'] as $term) {
            $page = $this->actingAs($this->admin)->get('/admin/customers?q='.urlencode($term))->assertOk();

            $this->assertCount(1, $page->viewData('customers'), "searching [$term]");
            $this->assertSame($wanted->id, $page->viewData('customers')->first()->id);
        }
    }

    public function test_it_can_be_sorted_by_spend(): void
    {
        $small = $this->customer(['name' => 'Small']);
        $big = $this->customer(['name' => 'Big']);

        $this->order($small, 5.00);
        $this->order($big, 500.00);

        $first = $this->actingAs($this->admin)
            ->get('/admin/customers?sort=spend')
            ->viewData('customers')->first();

        $this->assertSame($big->id, $first->id);
    }

    public function test_a_made_up_sort_falls_back(): void
    {
        $page = $this->actingAs($this->admin)->get('/admin/customers?sort=nonsense')->assertOk();

        $this->assertSame('recent', $page->viewData('sort'));
    }

    public function test_staff_accounts_are_not_listed_as_customers(): void
    {
        $this->customer(['name' => 'A Customer']);

        $page = $this->actingAs($this->admin)->get('/admin/customers')->assertOk();

        // The signed-in admin's own email is in the layout header, so check
        // the list itself rather than the whole page.
        $this->assertCount(1, $page->viewData('customers'));
        $this->assertNotContains(
            $this->admin->id,
            $page->viewData('customers')->pluck('id')->all()
        );
    }

    public function test_the_summary_excludes_cancelled_orders_from_revenue(): void
    {
        $buyer = $this->customer();

        $this->order($buyer, 10.00);
        $this->order($buyer, 90.00, status: 'Cancelled');

        $summary = $this->actingAs($this->admin)->get('/admin/customers')->viewData('summary');

        $this->assertSame(10.0, $summary['revenue']);
        $this->assertSame(1, $summary['with_orders']);
    }

    public function test_the_detail_page_shows_history_and_favourites(): void
    {
        $buyer = $this->customer(['name' => 'Chan Sophea']);
        $order = $this->order($buyer, 12.00);

        $page = $this->actingAs($this->admin)
            ->get(route('admin.customers.show', $buyer))
            ->assertOk()
            ->assertSee('Chan Sophea')
            ->assertSee($order->order_number)
            ->assertSee('Kampot Pepper');

        $this->assertSame(12.0, $page->viewData('spend'));
        $this->assertSame(1, $page->viewData('orderCount'));
        $this->assertSame(12.0, $page->viewData('averageOrder'));
    }

    public function test_the_detail_page_copes_with_a_customer_who_never_ordered(): void
    {
        $quiet = $this->customer(['name' => 'Never Ordered']);

        $page = $this->actingAs($this->admin)
            ->get(route('admin.customers.show', $quiet))
            ->assertOk()
            ->assertSee('has not ordered yet');

        // No division by zero on the average.
        $this->assertSame(0.0, $page->viewData('averageOrder'));
    }

    public function test_the_list_does_not_query_once_per_customer(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->order($this->customer(), 5.00);
        }

        DB::enableQueryLog();

        $this->actingAs($this->admin)->get('/admin/customers')->assertOk();

        $this->assertLessThan(20, count(DB::getQueryLog()));
    }
}
