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
 *
 * Both actions answer JSON when the page asks for it. A full round trip would
 * redirect back to a freshly rendered checkout, and every delivery detail the
 * customer had typed but not yet submitted would be gone — including the
 * province, which is required, so the next Place Order would fail validation.
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
            return $this->respond($request, false, __('site.coupon.too_many'));
        }

        RateLimiter::hit($throttleKey, decaySeconds: 60);

        $cart = Auth::user()->cart?->load('items.product');

        if (! $cart || $cart->items->isEmpty()) {
            return redirect()->route('cart.index');
        }

        $subtotal = $cart->totalPrice();
        $coupon = Coupon::with('categories', 'products')->where('code', strtoupper(trim($request->input('code'))))->first();

        if (! $coupon) {
            return $this->respond($request, false, __('site.coupon.not_found'));
        }

        if ($reason = $coupon->reasonUnusable($subtotal)) {
            return $this->respond($request, false, $reason);
        }

        // A category- or product-limited coupon may be perfectly valid and
        // still discount nothing in this particular cart.
        $discount = $coupon->discountForCart($cart->items);

        if ($discount <= 0) {
            return $this->respond($request, false, __('site.coupon.nothing_eligible'));
        }

        $request->session()->put(self::SESSION_KEY, $coupon->code);

        return $this->respond($request, true, __('site.coupon.applied', [
            'code' => $coupon->code,
            'amount' => '$' . number_format($discount, 2),
        ]), [
            'code' => $coupon->code,
            'discount' => round($discount, 2),
        ]);
    }

    public function remove(Request $request)
    {
        $request->session()->forget(self::SESSION_KEY);

        return $this->respond($request, true, __('site.coupon.removed'), [
            'code' => null,
            'discount' => 0,
        ]);
    }

    /**
     * Answer in whichever form the caller asked for.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function respond(Request $request, bool $ok, string $message, array $extra = [])
    {
        if ($request->expectsJson()) {
            return response()->json(
                array_merge(['ok' => $ok, 'message' => $message], $extra),
                $ok ? 200 : 422
            );
        }

        return back()->with($ok ? 'coupon_success' : 'coupon_error', $message);
    }
}
