<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KhqrRenewTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Order $order;

    protected Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.cutluy.key' => 'ck_test_key']);

        $this->user = User::factory()->create();

        $category = Category::create(['name' => 'Fruit']);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Mango',
            'description' => 'Sweet',
            'price' => 1.50,
            'stock' => 10,
            'status' => true,
        ]);

        $this->order = Order::create([
            'user_id' => $this->user->id,
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
        ]);

        $this->order->items()->create([
            'product_id' => $product->id,
            'product_name' => 'Mango',
            'price' => 1.50,
            'quantity' => 2,
        ]);

        $this->payment = $this->order->payment()->create([
            'method' => Payment::METHOD_KHQR,
            'status' => Payment::STATUS_PENDING,
            'cutluy_payment_id' => 'OLD_PAY',
            'cutluy_status' => 'pending',
            'amount' => 4.50,
            'currency' => 'USD',
            'qr_string' => 'OLDQRPAYLOAD',
            'expires_at' => now()->subMinutes(3),   // lapsed
        ]);
    }

    /**
     * @param  array<string, mixed>  $freshOverrides
     */
    protected function fakeCutLuy(string $currentStatus = 'expired', array $freshOverrides = []): void
    {
        Http::fake([
            // Reading the old payment.
            'cutluy.com/v1/payments/OLD_PAY' => Http::response([
                'id' => 'OLD_PAY',
                'status' => $currentStatus,
                'amount' => '4.50',
                'reference_id' => 'order_'.$this->order->id,
            ]),
            // Creating the replacement.
            'cutluy.com/v1/payments' => Http::response(array_merge([
                'id' => 'NEW_PAY',
                'status' => 'pending',
                'amount' => '4.50',
                'currency' => 'USD',
                'qr_string' => 'NEWQRPAYLOAD',
                'expires_at' => now()->addMinutes(5)->toIso8601String(),
            ], $freshOverrides), 201),
        ]);
    }

    public function test_opening_the_pay_page_on_a_lapsed_qr_fetches_a_new_one(): void
    {
        $this->fakeCutLuy();

        $this->actingAs($this->user)
            ->get('/orders/'.$this->order->order_number.'/pay')
            ->assertOk()
            ->assertSee('NEWQRPAYLOAD', false)
            ->assertDontSee('OLDQRPAYLOAD', false);

        $this->payment->refresh();

        $this->assertSame('NEW_PAY', $this->payment->cutluy_payment_id);
        $this->assertSame(1, $this->payment->renewals);
        $this->assertTrue($this->payment->expires_at->isFuture());
    }

    public function test_the_renewal_carries_an_idempotency_key_cutluy_has_not_seen(): void
    {
        $this->fakeCutLuy();

        $this->actingAs($this->user)->post('/orders/'.$this->order->order_number.'/pay/new');

        // Reusing "order_{id}" would make CutLuy replay the expired payment.
        Http::assertSent(fn ($request) => $request->url() === 'https://cutluy.com/v1/payments'
            && $request['idempotency_key'] === 'order_'.$this->order->id.'_r1');
    }

    public function test_a_second_renewal_gets_its_own_key(): void
    {
        $this->payment->update(['renewals' => 1]);
        $this->fakeCutLuy();

        $this->actingAs($this->user)->post('/orders/'.$this->order->order_number.'/pay/new');

        Http::assertSent(fn ($request) => $request->url() === 'https://cutluy.com/v1/payments'
            && $request['idempotency_key'] === 'order_'.$this->order->id.'_r2');

        $this->assertSame(2, $this->payment->fresh()->renewals);
    }

    public function test_the_order_keeps_its_stock_through_a_renewal(): void
    {
        $before = Product::first()->stock;

        $this->fakeCutLuy();

        $this->actingAs($this->user)->post('/orders/'.$this->order->order_number.'/pay/new');

        $this->assertSame($before, Product::first()->fresh()->stock);
        $this->assertSame('Pending', $this->order->fresh()->status);
    }

    public function test_a_payment_that_landed_late_is_honoured_instead_of_reissued(): void
    {
        // The customer paid in the seconds before the code lapsed.
        $this->fakeCutLuy(currentStatus: 'paid');

        $this->actingAs($this->user)
            ->post('/orders/'.$this->order->order_number.'/pay/new')
            ->assertRedirect(route('orders.show', $this->order));

        $this->assertTrue($this->payment->fresh()->isPaid());
        $this->assertSame('Confirmed', $this->order->fresh()->status);

        // No second QR was ever created for money we already have.
        Http::assertNotSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://cutluy.com/v1/payments');
    }

    public function test_a_released_order_is_not_given_another_qr(): void
    {
        // Reconciliation already expired this one and put the stock back.
        $this->payment->update(['status' => Payment::STATUS_EXPIRED]);
        $this->order->update(['status' => 'Cancelled']);

        Http::fake();

        $this->actingAs($this->user)
            ->post('/orders/'.$this->order->order_number.'/pay/new')
            ->assertRedirect(route('orders.show', $this->order))
            ->assertSessionHas('error');

        Http::assertNothingSent();
    }

    public function test_renewals_are_capped(): void
    {
        $this->payment->update(['renewals' => Payment::MAX_RENEWALS]);

        Http::fake();

        $this->actingAs($this->user)
            ->post('/orders/'.$this->order->order_number.'/pay/new')
            ->assertRedirect(route('orders.show', $this->order))
            ->assertSessionHas('error');

        Http::assertNothingSent();
    }

    public function test_refreshing_the_page_does_not_mint_a_qr_each_time(): void
    {
        $this->fakeCutLuy();

        for ($i = 0; $i < 4; $i++) {
            $this->actingAs($this->user)->get('/orders/'.$this->order->order_number.'/pay');
        }

        // The first view renews; the rest are inside the throttle window.
        $this->assertSame(1, $this->payment->fresh()->renewals);
    }

    public function test_a_still_valid_qr_is_left_alone(): void
    {
        $this->payment->update(['expires_at' => now()->addMinutes(4)]);

        Http::fake();

        $this->actingAs($this->user)
            ->get('/orders/'.$this->order->order_number.'/pay')
            ->assertOk()
            ->assertSee('OLDQRPAYLOAD', false);

        Http::assertNothingSent();
    }

    public function test_another_customer_cannot_renew_someone_elses_qr(): void
    {
        Http::fake();

        $this->actingAs(User::factory()->create())
            ->post('/orders/'.$this->order->order_number.'/pay/new')
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_a_provider_outage_leaves_the_order_untouched(): void
    {
        Http::fake([
            'cutluy.com/v1/payments/OLD_PAY' => Http::response(['error' => ['code' => 'server_error']], 500),
            'cutluy.com/v1/payments' => Http::response([], 500),
        ]);

        $this->actingAs($this->user)
            ->post('/orders/'.$this->order->order_number.'/pay/new')
            ->assertSessionHas('error');

        $this->payment->refresh();

        $this->assertSame('OLD_PAY', $this->payment->cutluy_payment_id);
        $this->assertSame(0, $this->payment->renewals);
        $this->assertSame('Pending', $this->order->fresh()->status);
    }
}
