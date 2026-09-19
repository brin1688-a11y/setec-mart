<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EditUnpaidOrderTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Product $mango;

    protected Product $rice;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.cutluy.key' => 'ck_test_key']);

        $this->user = User::factory()->create();

        $category = Category::create(['name' => 'Grocery']);

        $this->mango = Product::create([
            'category_id' => $category->id,
            'name' => 'Mango',
            'description' => 'Sweet',
            'price' => 2.00,
            'stock' => 100,
            'status' => true,
        ]);

        $this->rice = Product::create([
            'category_id' => $category->id,
            'name' => 'Rice 5kg',
            'description' => 'Jasmine',
            'price' => 9.00,
            'stock' => 100,
            'status' => true,
        ]);
    }

    /**
     * An order as checkout leaves it: priced, with stock already reserved.
     *
     * @param  array<int, array{0: Product, 1: int}>  $lines
     */
    protected function order(array $lines, array $overrides = []): Order
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
            'subtotal' => 0,
            'discount' => 0,
            'delivery_fee' => 0,
            'total' => 0,
        ], $overrides));

        foreach ($lines as [$product, $quantity]) {
            $order->items()->create([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'price' => $product->price,
                'quantity' => $quantity,
            ]);

            $product->decrement('stock', $quantity);
        }

        $order->refresh()->reprice();

        $order->payment()->create([
            'method' => Payment::METHOD_KHQR,
            'status' => Payment::STATUS_PENDING,
            'cutluy_payment_id' => 'PAY'.$order->id,
            'cutluy_status' => 'pending',
            'amount' => $order->total,
            'currency' => 'USD',
            'qr_string' => 'OLDQR',
        ]);

        return $order->fresh();
    }

    protected function cutLuySays(Order $order, string $status = 'pending'): void
    {
        $id = $order->payment->cutluy_payment_id;

        Http::fake([
            'cutluy.com/v1/payments/'.$id => Http::response([
                'id' => $id,
                'status' => $status,
                // The webhook checks the amount before settling, so quote what
                // the order actually came to.
                'amount' => (string) $order->total,
            ]),
            'cutluy.com/v1/payments' => Http::response([
                'id' => 'NEW_PAY_'.$order->id, 'status' => 'pending',
                'qr_string' => 'NEWQR', 'expires_at' => now()->addMinutes(5)->toIso8601String(),
            ], 201),
        ]);
    }

    protected function remove(Order $order, $item)
    {
        return $this->actingAs($this->user)
            ->delete(route('orders.items.remove', [$order, $item]));
    }

    public function test_an_item_can_be_taken_off_an_unpaid_order(): void
    {
        // $4 of mango + $9 of rice = $13, under the $20 free-delivery mark.
        $order = $this->order([[$this->mango, 2], [$this->rice, 1]]);

        $this->assertSame('13.00', $order->subtotal);
        $this->assertSame('1.50', $order->delivery_fee);

        $this->cutLuySays($order);

        $this->remove($order, $order->items->firstWhere('product_name', 'Rice 5kg'))
            ->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('success');

        $order->refresh();

        $this->assertCount(1, $order->items);
        $this->assertSame('4.00', $order->subtotal);
        $this->assertSame('5.50', $order->total);       // 4.00 + 1.50 delivery
        $this->assertSame(100, $this->rice->fresh()->stock);   // handed back
        $this->assertSame(98, $this->mango->fresh()->stock);   // still reserved
    }

    public function test_delivery_is_charged_again_when_the_order_drops_below_the_threshold(): void
    {
        // $22 of rice ships free in Phnom Penh; removing a line should not
        // leave the order shipping free on $13.
        $order = $this->order([[$this->rice, 2], [$this->mango, 2]]);

        $this->assertSame('22.00', $order->subtotal);
        $this->assertSame('0.00', $order->delivery_fee);

        $this->cutLuySays($order);

        $this->remove($order, $order->items->firstWhere('product_name', 'Rice 5kg'));

        $order->refresh();

        $this->assertSame('4.00', $order->subtotal);
        $this->assertSame('1.50', $order->delivery_fee);
        $this->assertSame('5.50', $order->total);
    }

    public function test_a_coupon_that_no_longer_qualifies_is_dropped(): void
    {
        Coupon::create([
            'code' => 'SAVE5',
            'type' => 'fixed',
            'value' => 5.00,
            'min_subtotal' => 20.00,
            'is_active' => true,
        ]);

        $order = $this->order([[$this->rice, 2], [$this->mango, 2]], ['coupon_code' => 'SAVE5']);

        $this->assertSame('5.00', $order->discount);

        $this->cutLuySays($order);

        $this->remove($order, $order->items->firstWhere('product_name', 'Rice 5kg'));

        $order->refresh();

        // $4 is below the coupon's $20 minimum, so it falls away.
        $this->assertNull($order->coupon_code);
        $this->assertSame('0.00', $order->discount);
        $this->assertSame('5.50', $order->total);
    }

    public function test_a_coupon_that_still_qualifies_is_kept(): void
    {
        Coupon::create([
            'code' => 'SAVE5',
            'type' => 'fixed',
            'value' => 5.00,
            'min_subtotal' => 10.00,
            'is_active' => true,
        ]);

        $order = $this->order([[$this->rice, 3], [$this->mango, 2]], ['coupon_code' => 'SAVE5']);

        $this->cutLuySays($order);

        $this->remove($order, $order->items->firstWhere('product_name', 'Mango'));

        $order->refresh();

        $this->assertSame('27.00', $order->subtotal);
        $this->assertSame('SAVE5', $order->coupon_code);
        $this->assertSame('5.00', $order->discount);
        $this->assertSame('22.00', $order->total);
    }

    public function test_removing_the_last_item_cancels_the_order(): void
    {
        $order = $this->order([[$this->mango, 3]]);

        $this->cutLuySays($order);

        $this->remove($order, $order->items->first())
            ->assertRedirect(route('orders.index'))
            ->assertSessionHas('success');

        $order->refresh();

        $this->assertSame('Cancelled', $order->status);
        $this->assertSame(Payment::STATUS_CANCELLED, $order->payment->status);
        $this->assertSame(100, $this->mango->fresh()->stock);
    }

    public function test_an_emptied_order_still_records_what_it_was(): void
    {
        // A cancelled order with no lines at all tells nobody what happened,
        // so removed lines are kept and simply stop being charged for.
        $order = $this->order([[$this->mango, 3]]);

        $this->cutLuySays($order);

        $this->remove($order, $order->items->first());

        $order->refresh();

        $this->assertCount(0, $order->items);
        $this->assertCount(1, $order->allItems);
        $this->assertSame('Mango', $order->allItems->first()->product_name);
        $this->assertTrue($order->allItems->first()->trashed());
    }

    public function test_the_list_still_shows_what_an_emptied_order_contained(): void
    {
        $order = $this->order([[$this->mango, 3]]);

        $this->cutLuySays($order);

        $this->remove($order, $order->items->first());

        $this->actingAs($this->user)
            ->get('/orders')
            ->assertOk()
            ->assertSee($order->order_number)
            ->assertSee('Mango');
    }

    public function test_a_removed_line_is_shown_on_the_order_but_not_charged(): void
    {
        $order = $this->order([[$this->mango, 2], [$this->rice, 1]]);

        $this->cutLuySays($order);

        $this->remove($order, $order->items->firstWhere('product_name', 'Rice 5kg'));

        $this->actingAs($this->user)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('Rice 5kg')
            ->assertSee('removed');

        // The totals only count what is left.
        $this->assertSame('4.00', $order->fresh()->subtotal);
    }

    public function test_a_removed_line_cannot_be_removed_again(): void
    {
        $order = $this->order([[$this->mango, 2], [$this->rice, 1]]);
        $rice = $order->items->firstWhere('product_name', 'Rice 5kg');

        $this->cutLuySays($order);

        $this->remove($order, $rice);
        $this->remove($order, $rice);

        // Its stock came back exactly once.
        $this->assertSame(100, $this->rice->fresh()->stock);
        $this->assertCount(1, $order->fresh()->items);
    }

    public function test_the_old_qr_is_retired_so_the_old_amount_cannot_be_paid(): void
    {
        $order = $this->order([[$this->mango, 2], [$this->rice, 1]]);

        $this->cutLuySays($order);

        $this->remove($order, $order->items->firstWhere('product_name', 'Rice 5kg'));

        // The code that was drawn for $14.50 must not still be on the page.
        $this->assertNull($order->fresh()->payment->qr_string);
    }

    public function test_the_pay_page_then_issues_a_code_for_the_new_total(): void
    {
        $order = $this->order([[$this->mango, 2], [$this->rice, 1]]);

        $this->cutLuySays($order);

        $this->remove($order, $order->items->firstWhere('product_name', 'Rice 5kg'));

        $this->actingAs($this->user)
            ->get(route('payments.khqr', $order))
            ->assertOk()
            ->assertSee('NEWQR', false);

        // $4.00 + $1.50 delivery — not the $14.50 the first code was for.
        Http::assertSent(fn ($request) => $request->url() === 'https://cutluy.com/v1/payments'
            && $request['amount'] === 5.5);
    }

    public function test_a_paid_order_cannot_have_items_removed(): void
    {
        $order = $this->order([[$this->mango, 2], [$this->rice, 1]]);
        $order->payment->update(['status' => Payment::STATUS_PAID]);

        Http::fake();

        $this->remove($order, $order->items->first())->assertSessionHas('error');

        $this->assertCount(2, $order->fresh()->items);
        $this->assertSame(98, $this->mango->fresh()->stock);
    }

    public function test_an_order_the_shop_has_started_cannot_have_items_removed(): void
    {
        $order = $this->order([[$this->mango, 2], [$this->rice, 1]]);
        $order->update(['status' => 'Preparing']);

        Http::fake();

        $this->remove($order, $order->items->first())->assertSessionHas('error');

        $this->assertCount(2, $order->fresh()->items);
    }

    public function test_money_that_landed_first_stops_the_edit(): void
    {
        $order = $this->order([[$this->mango, 2], [$this->rice, 1]]);

        $this->cutLuySays($order, 'paid');

        $this->remove($order, $order->items->first())
            ->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('success');

        $order->refresh();

        $this->assertCount(2, $order->items);
        $this->assertTrue($order->payment->isPaid());
        $this->assertSame(98, $this->mango->fresh()->stock);
    }

    public function test_one_customer_cannot_edit_anothers_order(): void
    {
        $order = $this->order([[$this->mango, 2], [$this->rice, 1]]);

        Http::fake();

        $this->actingAs(User::factory()->create())
            ->delete(route('orders.items.remove', [$order, $order->items->first()]))
            ->assertForbidden();

        $this->assertCount(2, $order->fresh()->items);
    }

    public function test_an_item_from_a_different_order_is_rejected(): void
    {
        $mine = $this->order([[$this->mango, 2]]);
        $other = $this->order([[$this->rice, 1]]);

        Http::fake();

        $this->actingAs($this->user)
            ->delete(route('orders.items.remove', [$mine, $other->items->first()]))
            ->assertNotFound();

        $this->assertCount(1, $other->fresh()->items);
    }

    public function test_removing_the_same_item_twice_returns_the_stock_once(): void
    {
        $order = $this->order([[$this->mango, 2], [$this->rice, 1]]);
        $item = $order->items->firstWhere('product_name', 'Rice 5kg');

        $this->cutLuySays($order);

        $this->remove($order, $item);
        $this->remove($order, $item);

        $this->assertSame(100, $this->rice->fresh()->stock);
    }

    public function test_the_detail_page_offers_removal_only_while_it_is_allowed(): void
    {
        $order = $this->order([[$this->mango, 2], [$this->rice, 1]]);
        $item = $order->items->first();

        $this->actingAs($this->user)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee(route('orders.items.remove', [$order, $item]), false);

        $order->update(['status' => 'Preparing']);

        $this->actingAs($this->user)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertDontSee(route('orders.items.remove', [$order, $item]), false);
    }
}
