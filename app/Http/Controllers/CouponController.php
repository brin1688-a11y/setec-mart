<?php

namespace App\Http\Controllers;

use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Applying and removing a coupon at checkout.
 *
 * The chosen code lives in the session, never in a hidden form field — the
 * discount is recalculated from the database when the order is placed, so a
 * code cannot be edited in the page.
 */
class CouponController extends Controller
{
    public const SESSION_KEY = 'checkout.coupon';

    public function apply(Request $request)
    {
        $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        // Guessing codes is a real pastime; cap the attempts.
        $throttleKey = 'coupon:' . Auth::id();

        if (RateLimiter::tooManyAttempts($throttleKey, maxAttempts: 10)) {
            return back()->with('coupon_error', __('site.coupon.too_many'));
        }

        RateLimiter::hit($throttleKey, decaySeconds: 60);

        $cart = Auth::user()->cart?->load('items.product');

        if (! $cart || $cart->items->isEmpty()) {
            return redirect()->route('cart.index');
        }

        $subtotal = $cart->totalPrice();
        $coupon = Coupon::with('categories', 'products')->where('code', strtoupper(trim($request->input('code'))))->first();

        if (! $coupon) {
            return back()->with('coupon_error', __('site.coupon.not_found'));
        }

        if ($reason = $coupon->reasonUnusable($subtotal)) {
            return back()->with('coupon_error', $reason);
        }

        // A category- or product-limited coupon may be perfectly valid and
        // still discount nothing in this particular cart.
        $discount = $coupon->discountForCart($cart->items);

        if ($discount <= 0) {
            return back()->with('coupon_error', __('site.coupon.nothing_eligible'));
        }

        $request->session()->put(self::SESSION_KEY, $coupon->code);

        return back()->with('coupon_success', __('site.coupon.applied', [
            'code' => $coupon->code,
            'amount' => '$' . number_format($discount, 2),
        ]));
    }

    public function remove(Request $request)
    {
        $request->session()->forget(self::SESSION_KEY);

        return back();
    }
}
