<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\QueuedResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_page_offers_a_way_out(): void
    {
        $this->get('/login')->assertOk()->assertSee(route('password.request'), false);
    }

    public function test_a_link_is_sent_to_an_address_that_has_an_account(): void
    {
        Notification::fake();

        $user = User::factory()->create(['role' => 'customer']);

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHas('status');

        Notification::assertSentTo($user, QueuedResetPassword::class);
    }

    public function test_an_unknown_address_gets_the_same_answer(): void
    {
        // A different reply would turn this form into a way of finding out
        // who shops here.
        Notification::fake();

        $known = User::factory()->create(['role' => 'customer']);

        $one = $this->post('/forgot-password', ['email' => $known->email])->getSession()->get('status');
        $two = $this->post('/forgot-password', ['email' => 'nobody@example.test'])->getSession()->get('status');

        $this->assertSame($one, $two);
        Notification::assertSentTimes(QueuedResetPassword::class, 1);
    }

    public function test_a_google_only_account_is_not_sent_a_link(): void
    {
        // It has no password to reset, and sending one would quietly give the
        // account a second way in.
        Notification::fake();

        $user = User::factory()->create([
            'role' => 'customer',
            'google_id' => 'g-123',
            'password' => null,
        ]);

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHas('status');

        Notification::assertNothingSent();
    }

    public function test_the_password_can_be_changed_with_the_token(): void
    {
        Notification::fake();

        $user = User::factory()->create(['role' => 'customer']);

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, QueuedResetPassword::class, function ($notification) use ($user) {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'a-brand-new-password',
                'password_confirmation' => 'a-brand-new-password',
            ])->assertRedirect(route('login'));

            return true;
        });

        $this->assertTrue(Hash::check('a-brand-new-password', $user->fresh()->password));
    }

    public function test_a_made_up_token_changes_nothing(): void
    {
        $user = User::factory()->create(['role' => 'customer', 'password' => Hash::make('the-old-one')]);

        $this->post('/reset-password', [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('the-old-one', $user->fresh()->password));
    }

    public function test_the_reset_form_is_rate_limited(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $response = $this->post('/forgot-password', ['email' => 'someone@example.test']);
        }

        $response->assertStatus(429);
    }

    public function test_the_link_works_even_when_already_signed_in(): void
    {
        // The link arrives by email and gets opened wherever the person is,
        // often on a phone already signed in. While these routes were
        // guest-only they bounced to the home page with no explanation.
        Notification::fake();

        $user = User::factory()->create(['role' => 'customer']);
        $token = Password::createToken($user);

        $this->actingAs($user)
            ->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->assertOk()
            ->assertSee('Choose a new password');

        // Opening it signed them out, which is what choosing a new password
        // means anyway.
        $this->assertGuest();
    }

    public function test_someone_signed_in_can_finish_the_reset(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $token = Password::createToken($user);

        $this->actingAs($user)->get(route('password.reset', ['token' => $token, 'email' => $user->email]));

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'chosen-while-signed-in',
            'password_confirmation' => 'chosen-while-signed-in',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('chosen-while-signed-in', $user->fresh()->password));
    }
}
