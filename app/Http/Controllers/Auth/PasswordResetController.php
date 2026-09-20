<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;

/**
 * Forgotten passwords.
 *
 * The replies never say whether an address is registered. Telling a stranger
 * "no account with that email" turns this form into a way to find out who
 * shops here, so a known address and an unknown one get the same answer.
 */
class PasswordResetController extends Controller
{
    public function showRequest()
    {
        return view('auth.forgot-password');
    }

    public function sendLink(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);

        $user = User::where('email', $request->input('email'))->first();

        // An account with no password signs in through Google. Resetting
        // would quietly hand it a second way in, so send nothing and answer
        // the same as for any other address.
        if ($user && $user->signsInWithGoogle() && $user->password === null) {
            return back()->with('status', $this->sameAnswer());
        }

        Password::sendResetLink($request->only('email'));

        return back()->with('status', $this->sameAnswer());
    }

    public function showReset(Request $request, string $token)
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    public function reset(Request $request)
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return redirect()->route('login')
            ->with('success', 'Your password has been changed. Please sign in.');
    }

    protected function sameAnswer(): string
    {
        return 'If that address has an account, a link to choose a new password is on its way.';
    }
}
