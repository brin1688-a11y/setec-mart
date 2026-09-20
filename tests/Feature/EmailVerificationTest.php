<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Notifications\QueuedVerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function verifyLinkFor(User $user): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);
    }

    public function test_registering_sends_a_link(): void
    {
        Notification::fake();

        $this->post('/register', [
            'name' => 'Sok Dara',
            'email' => 'sokdara@example.test',
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
        ])->assertRedirect(route('verification.notice'));

        $user = User::where('email', 'sokdara@example.test')->firstOrFail();

        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, QueuedVerifyEmail::class);
    }

    public function test_the_link_confirms_the_address(): void
    {
        $user = User::factory()->unverified()->create(['role' => 'customer']);

        $this->actingAs($user)->get($this->verifyLinkFor($user))->assertRedirect(route('home'));

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_a_tampered_link_does_not_confirm_anything(): void
    {
        $user = User::factory()->unverified()->create(['role' => 'customer']);

        $this->actingAs($user)
            ->get(route('verification.verify', ['id' => $user->id, 'hash' => sha1('someone.else@example.test')]))
            ->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_signing_in_with_google_needs_no_confirmation(): void
    {
        // Google only hands back an address it has already proven.
        $user = User::factory()->create([
            'role' => 'customer',
            'google_id' => 'g-123',
            'email_verified_at' => now(),
        ]);

        $this->assertTrue($user->signsInWithGoogle());
        $this->assertTrue($user->hasVerifiedEmail());
    }

    public function test_browsing_and_the_cart_stay_open_before_confirming(): void
    {
        $user = User::factory()->unverified()->create(['role' => 'customer']);

        $this->actingAs($user)->get('/products')->assertOk();
        $this->actingAs($user)->get('/cart')->assertOk();
        $this->actingAs($user)->get('/orders')->assertOk();
    }

    public function test_checkout_waits_for_a_confirmed_address(): void
    {
        $user = User::factory()->unverified()->create(['role' => 'customer']);

        $product = Product::create([
            'category_id' => Category::create(['name' => 'Grocery'])->id,
            'name' => 'Rice', 'description' => 'Jasmine',
            'price' => 5.00, 'stock' => 10, 'status' => true,
        ]);

        $cart = Cart::create(['user_id' => $user->id]);
        $cart->items()->create(['product_id' => $product->id, 'quantity' => 1]);

        $this->actingAs($user)->get('/checkout')->assertRedirect(route('verification.notice'));

        $this->actingAs($user)->post('/checkout', [
            'name' => 'Sok Dara', 'phone' => '012345678',
            'province' => 'Phnom Penh', 'district' => 'Chamkar Mon',
            'commune' => 'Tonle Bassac', 'address' => 'St. 271',
            'payment_method' => 'cod',
        ])->assertRedirect(route('verification.notice'));

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_a_confirmed_customer_reaches_checkout(): void
    {
        $user = User::factory()->create(['role' => 'customer']);

        $product = Product::create([
            'category_id' => Category::create(['name' => 'Grocery'])->id,
            'name' => 'Rice', 'description' => 'Jasmine',
            'price' => 5.00, 'stock' => 10, 'status' => true,
        ]);

        $cart = Cart::create(['user_id' => $user->id]);
        $cart->items()->create(['product_id' => $product->id, 'quantity' => 1]);

        $this->actingAs($user)->get('/checkout')->assertOk();
    }

    public function test_the_link_can_be_sent_again(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create(['role' => 'customer']);

        $this->actingAs($user)->post(route('verification.send'))->assertSessionHas('status');

        Notification::assertSentTo($user, QueuedVerifyEmail::class);
    }

    public function test_sending_again_is_rate_limited(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create(['role' => 'customer']);

        for ($i = 0; $i < 4; $i++) {
            $response = $this->actingAs($user)->post(route('verification.send'));
        }

        $response->assertStatus(429);
    }

    public function test_an_account_that_predates_this_is_left_alone(): void
    {
        // The migration marked every existing account verified; requiring it
        // of them would have locked out every customer the shop had.
        $user = User::factory()->create(['role' => 'customer']);

        $this->assertTrue($user->hasVerifiedEmail());
    }
}
