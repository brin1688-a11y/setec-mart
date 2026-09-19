<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Sign in with Google.
 *
 * Three cases are handled on the way back:
 *   1. We already know this Google account  -> sign that user in.
 *   2. We know the email but not the Google id -> link Google to the existing
 *      account, so someone who registered with a password does not end up
 *      with a second, separate account.
 *   3. Neither -> create a customer account.
 */
class GoogleController extends Controller
{
    /**
     * Send the customer over to Google.
     */
    public function redirect()
    {
        if (! $this->configured()) {
            return redirect()->route('login')
                ->withErrors(['email' => 'Google sign-in is not configured yet.']);
        }

        return Socialite::driver('google')->redirect();
    }

    /**
     * Google sends the customer back here.
     */
    public function callback()
    {
        if (! $this->configured()) {
            return redirect()->route('login')
                ->withErrors(['email' => 'Google sign-in is not configured yet.']);
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            // Covers a denied consent screen, an expired state token, and a
            // misconfigured client — none of which should show a stack trace.
            Log::warning('Google sign-in failed: ' . $e->getMessage());

            return redirect()->route('login')
                ->withErrors(['email' => 'We could not sign you in with Google. Please try again.']);
        }

        $email = $googleUser->getEmail();

        if (blank($email)) {
            return redirect()->route('login')
                ->withErrors(['email' => 'Your Google account did not share an email address.']);
        }

        $user = DB::transaction(function () use ($googleUser, $email) {
            $existing = User::where('google_id', $googleUser->getId())
                ->orWhere('email', $email)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                // Link the Google account on first use, and fill in a name or
                // avatar only where we do not already have one of our own.
                $existing->forceFill(array_filter([
                    'google_id' => $existing->google_id ?: $googleUser->getId(),
                    'name' => $existing->name ?: $googleUser->getName(),
                    // Only fill a gap — a picture they uploaded themselves wins.
                    'profile_picture' => $existing->profile_picture ?: $googleUser->getAvatar(),
                ]))->save();

                return $existing;
            }

            return User::create([
                'name' => $googleUser->getName() ?: Str::before($email, '@'),
                'email' => $email,
                'google_id' => $googleUser->getId(),
                // Google hands back a full URL; User::avatarUrl() knows to use
                // it as-is rather than treating it as a stored file path.
                'profile_picture' => $googleUser->getAvatar(),
                'role' => 'customer',
                // No password: this account signs in through Google only.
                'password' => null,
            ]);
        });

        Auth::login($user, remember: true);

        // A fresh session id after logging in, so a session fixated before
        // the redirect is useless.
        request()->session()->regenerate();

        return redirect()->intended(route('home'))
            ->with('success', 'Welcome, ' . $user->name . '!');
    }

    protected function configured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'));
    }
}
