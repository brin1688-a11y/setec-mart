<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

class GoogleSignInTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
            'services.google.redirect' => 'http://localhost/auth/google/callback',
        ]);
    }

    /**
     * Stand in for what Google hands back.
     */
    protected function fakeGoogleUser(
        string $id,
        string $email,
        ?string $name = 'Soat Borin',
        ?string $avatar = 'https://lh3.googleusercontent.com/a/photo=s96-c',
    ): void
    {
        $user = Mockery::mock(SocialiteUser::class);
        $user->shouldReceive('getId')->andReturn($id);
        $user->shouldReceive('getEmail')->andReturn($email);
        $user->shouldReceive('getName')->andReturn($name);
        $user->shouldReceive('getAvatar')->andReturn($avatar);

        Socialite::shouldReceive('driver->user')->andReturn($user);
    }

    public function test_a_new_visitor_gets_an_account_and_is_signed_in(): void
    {
        $this->fakeGoogleUser('google-123', 'new@gmail.com');

        $this->get('/auth/google/callback')->assertRedirect(route('home'));

        $user = User::where('email', 'new@gmail.com')->first();

        $this->assertNotNull($user);
        $this->assertSame('google-123', $user->google_id);
        $this->assertSame('customer', $user->role);
        $this->assertNull($user->password);
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_existing_password_account_is_linked_rather_than_duplicated(): void
    {
        $existing = User::factory()->create([
            'email' => 'shopper@gmail.com',
            'name' => 'Existing Name',
            'password' => Hash::make('their-own-password'),
            'role' => 'customer',
        ]);

        $this->fakeGoogleUser('google-456', 'shopper@gmail.com', 'Google Name');

        $this->get('/auth/google/callback')->assertRedirect(route('home'));

        $existing->refresh();

        // One account, now linked — not a second one.
        $this->assertSame(1, User::where('email', 'shopper@gmail.com')->count());
        $this->assertSame('google-456', $existing->google_id);

        // Their own name and password are left alone.
        $this->assertSame('Existing Name', $existing->name);
        $this->assertTrue(Hash::check('their-own-password', $existing->password));
        $this->assertAuthenticatedAs($existing);
    }

    public function test_returning_google_user_signs_into_the_same_account(): void
    {
        $user = User::factory()->create([
            'email' => 'repeat@gmail.com',
            'google_id' => 'google-789',
        ]);

        $this->fakeGoogleUser('google-789', 'repeat@gmail.com');

        $this->get('/auth/google/callback')->assertRedirect(route('home'));

        $this->assertSame(1, User::count());
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_admin_keeps_their_role_when_signing_in_with_google(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@gmail.com',
            'role' => 'admin',
        ]);

        $this->fakeGoogleUser('google-admin', 'admin@gmail.com');

        $this->get('/auth/google/callback');

        $this->assertSame('admin', $admin->fresh()->role);
        $this->assertTrue($admin->fresh()->isAdmin());
    }

    public function test_a_failure_at_google_shows_a_message_instead_of_an_error_page(): void
    {
        Socialite::shouldReceive('driver->user')->andThrow(new \RuntimeException('invalid state'));

        $this->get('/auth/google/callback')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_it_refuses_when_google_shares_no_email(): void
    {
        $this->fakeGoogleUser('google-noemail', '');

        $this->get('/auth/google/callback')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_it_says_so_when_credentials_are_missing(): void
    {
        config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

        $this->get('/auth/google')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
    }

    public function test_the_login_page_offers_the_google_button(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Continue with Google')
            ->assertSee(route('auth.google'), false);
    }

    public function test_the_register_page_offers_the_google_button(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertSee('Continue with Google');
    }

    public function test_a_signed_in_user_is_sent_away_from_the_google_route(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/auth/google')
            ->assertRedirect();
    }

    public function test_the_google_picture_becomes_the_profile_avatar(): void
    {
        $this->fakeGoogleUser('google-pic', 'pic@gmail.com');

        $this->get('/auth/google/callback');

        $user = User::where('email', 'pic@gmail.com')->first();

        $this->assertSame('https://lh3.googleusercontent.com/a/photo=s96-c', $user->profile_picture);

        // A full URL is served as-is, not treated as a path on the public disk.
        $this->assertSame('https://lh3.googleusercontent.com/a/photo=s96-c', $user->avatarUrl());

        $this->actingAs($user)->get('/profile')
            ->assertOk()
            ->assertSee('lh3.googleusercontent.com', false);
    }

    public function test_an_uploaded_picture_is_not_replaced_by_the_google_one(): void
    {
        $existing = User::factory()->create([
            'email' => 'haspic@gmail.com',
            'profile_picture' => 'avatars/mine.jpg',
        ]);

        $this->fakeGoogleUser('google-haspic', 'haspic@gmail.com');

        $this->get('/auth/google/callback');

        $this->assertSame('avatars/mine.jpg', $existing->fresh()->profile_picture);
        $this->assertStringContainsString('storage/avatars/mine.jpg', $existing->fresh()->avatarUrl());
    }

    public function test_a_user_with_no_picture_falls_back_to_the_default_avatar(): void
    {
        $user = User::factory()->create(['profile_picture' => null]);

        $this->assertStringContainsString('default-avatar.svg', $user->avatarUrl());
    }

    public function test_password_login_tells_a_google_only_account_to_use_google(): void
    {
        User::factory()->create([
            'email' => 'googleonly@gmail.com',
            'google_id' => 'google-only-1',
            'password' => null,
        ]);

        $this->post('/login', ['email' => 'googleonly@gmail.com', 'password' => 'guessing'])
            ->assertSessionHasErrors('email');

        $this->assertStringContainsString(
            'Continue with Google',
            session('errors')->first('email')
        );

        $this->assertGuest();
    }

    public function test_a_google_only_user_can_set_a_first_password(): void
    {
        $user = User::factory()->create([
            'email' => 'googleonly@gmail.com',
            'google_id' => 'google-only-2',
            'password' => null,
        ]);

        $this->actingAs($user)
            ->put('/profile/password', [
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
    }

    public function test_a_normal_user_still_must_confirm_their_current_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('the-old-one')]);

        $this->actingAs($user)
            ->put('/profile/password', [
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('the-old-one', $user->fresh()->password));
    }
}
