<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;

/**
 * Confirming the address a customer signed up with.
 *
 * Browsing and the account pages stay open to an unconfirmed customer; only
 * placing an order is held back. An address that was never confirmed is how
 * a run of junk orders gets placed, and it is also the only way the shop can
 * reach someone about a delivery.
 */
class EmailVerificationController extends Controller
{
    public function notice(Request $request)
    {
        return $request->user()->hasVerifiedEmail()
            ? redirect()->route('home')
            : view('auth.verify-email');
    }

    /**
     * The link in the email. Laravel checks the signature and the hash before
     * this method runs, so reaching it means the link is genuine and unexpired.
     */
    public function verify(EmailVerificationRequest $request)
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('home')->with('success', 'Your email is already confirmed.');
        }

        $request->fulfill();

        return redirect()->route('home')
            ->with('success', 'Thank you — your email is confirmed.');
    }

    public function resend(Request $request)
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('home');
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'A new link is on its way to '.$request->user()->email.'.');
    }
}
