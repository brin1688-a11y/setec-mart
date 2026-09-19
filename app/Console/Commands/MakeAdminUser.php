<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Creates an admin account, or promotes an existing customer to one.
 *
 * The password is asked for interactively and never appears in the arguments,
 * so it stays out of shell history and process listings.
 */
class MakeAdminUser extends Command
{
    protected $signature = 'user:admin
        {email? : The account to create or promote}
        {--name= : Display name, for a new account}';

    protected $description = 'Create an admin user, or promote an existing user to admin';

    public function handle(): int
    {
        $email = $this->argument('email') ?: $this->ask('Email address');

        $validator = Validator::make(['email' => $email], [
            'email' => ['required', 'email', 'max:255'],
        ]);

        if ($validator->fails()) {
            $this->error($validator->errors()->first('email'));

            return self::FAILURE;
        }

        $existing = User::where('email', $email)->first();

        return $existing
            ? $this->promote($existing)
            : $this->create($email);
    }

    /**
     * Flip an existing account over to the admin role.
     */
    protected function promote(User $user): int
    {
        if ($user->isAdmin()) {
            $this->info($user->email.' is already an admin.');

            return self::SUCCESS;
        }

        $this->line('Found existing user: '.$user->name.' <'.$user->email.'>');

        if (! $this->confirm('Promote this account to admin?', true)) {
            $this->line('Nothing changed.');

            return self::SUCCESS;
        }

        $user->update(['role' => 'admin']);

        $this->info('Promoted '.$user->email.' to admin. Their password is unchanged.');

        return self::SUCCESS;
    }

    /**
     * Create a brand new admin account.
     */
    protected function create(string $email): int
    {
        $name = $this->option('name') ?: $this->ask('Full name');

        // secret() keeps the password off the screen and out of history.
        $password = $this->secret('Password (min 8 characters)');
        $confirm = $this->secret('Confirm password');

        $validator = Validator::make([
            'name' => $name,
            'password' => $password,
            'password_confirmation' => $confirm,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'admin',
        ]);

        $this->info('Created admin account for '.$user->email.'.');
        $this->line('Sign in at /login, then the admin area is at /admin.');

        return self::SUCCESS;
    }
}
