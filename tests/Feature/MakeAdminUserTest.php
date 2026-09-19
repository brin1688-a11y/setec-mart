<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MakeAdminUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_new_admin(): void
    {
        $this->artisan('user:admin', ['email' => 'owner@freshmart.test'])
            ->expectsQuestion('Full name', 'Store Owner')
            ->expectsQuestion('Password (min 8 characters)', 'sup3rsecret')
            ->expectsQuestion('Confirm password', 'sup3rsecret')
            ->assertExitCode(0);

        $user = User::where('email', 'owner@freshmart.test')->first();

        $this->assertNotNull($user);
        $this->assertSame('admin', $user->role);
        $this->assertTrue($user->isAdmin());
        $this->assertTrue(Hash::check('sup3rsecret', $user->password));
    }

    public function test_it_promotes_an_existing_customer_without_touching_their_password(): void
    {
        $user = User::factory()->create([
            'email' => 'shopper@freshmart.test',
            'role' => 'customer',
            'password' => Hash::make('their-own-password'),
        ]);

        $this->artisan('user:admin', ['email' => 'shopper@freshmart.test'])
            ->expectsConfirmation('Promote this account to admin?', 'yes')
            ->assertExitCode(0);

        $user->refresh();

        $this->assertSame('admin', $user->role);
        $this->assertTrue(Hash::check('their-own-password', $user->password));
    }

    public function test_it_leaves_the_account_alone_when_the_promotion_is_declined(): void
    {
        User::factory()->create(['email' => 'shopper@freshmart.test', 'role' => 'customer']);

        $this->artisan('user:admin', ['email' => 'shopper@freshmart.test'])
            ->expectsConfirmation('Promote this account to admin?', 'no')
            ->assertExitCode(0);

        $this->assertSame('customer', User::where('email', 'shopper@freshmart.test')->first()->role);
    }

    public function test_it_rejects_a_mismatched_password(): void
    {
        $this->artisan('user:admin', ['email' => 'owner@freshmart.test'])
            ->expectsQuestion('Full name', 'Store Owner')
            ->expectsQuestion('Password (min 8 characters)', 'sup3rsecret')
            ->expectsQuestion('Confirm password', 'something-else')
            ->assertExitCode(1);

        $this->assertSame(0, User::where('email', 'owner@freshmart.test')->count());
    }

    public function test_it_rejects_a_short_password(): void
    {
        $this->artisan('user:admin', ['email' => 'owner@freshmart.test'])
            ->expectsQuestion('Full name', 'Store Owner')
            ->expectsQuestion('Password (min 8 characters)', 'short')
            ->expectsQuestion('Confirm password', 'short')
            ->assertExitCode(1);

        $this->assertSame(0, User::where('email', 'owner@freshmart.test')->count());
    }

    public function test_it_rejects_a_malformed_email(): void
    {
        $this->artisan('user:admin', ['email' => 'not-an-email'])
            ->assertExitCode(1);
    }
}
