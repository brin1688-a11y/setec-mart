<?php

namespace App\Http\Controllers;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\Payment;
use App\Rules\CambodianPhone;
use App\Rules\TelegramHandle;
use App\Support\Cambodia;
use App\Services\CutLuy\CutLuyClient;
use App\Services\CutLuy\Exceptions\AccountSuspendedException;
use App\Services\CutLuy\Exceptions\CutLuyException;
use App\Services\CutLuy\Exceptions\QuotaExceededException;
use App\Services\CutLuy\Exceptions\RateLimitedException;
use App\Services\CutLuy\Exceptions\UnauthorizedException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class CheckoutController extends Controller
{
    public function __construct(protected CutLuyClient $cutluy)
    {
    }

    /**
     * Show the checkout page (delivery info + payment method + summary).
     */
    public function index()
    {
        $cart = Auth::user()->cart?->load('items.product');

        if (!$cart || $cart->items->isEmpty()) {
            return redirect()->route('cart.index')->with('error', 'Your cart is empty.');
        }

        $subtotal = $cart->totalPrice();
        $user = Auth::user();

        [$coupon, $discount] = $this->resolveCoupon($subtotal, $cart->items);

        return view('checkout.index', [
            'cart' => $cart,
            'subtotal' => $subtotal,
            'coupon' => $coupon,
            'discount' => $discount,
            'provinces' => Cambodia::provinceOptions(),
            'provinceGroups' => Cambodia::provinceGroups(),
            'user' => $user,
            // Quoted live in the page as the province changes; the server
            // recomputes it on submit so the shown price cannot be gamed.
            // Each zone's price, free threshold and lead time, so the page
            // can quote all three the moment a province is picked.
            'zoneRules' => Cambodia::zones(),
            'zoneOf' => collect(Cambodia::provinces())->map(fn ($p) => $p['zone']),
            'toFreeDelivery' => Cambodia::amountToFreeDelivery($subtotal, $user->province),
        ]);
    }

    /**
     * Place the order: validate stock, create Order/OrderItems/Payment and
     * decrement stock in one transaction.
     *
     * Cash on delivery clears the cart and finishes here. KHQR only clears the
     * cart once CutLuy has handed us a QR to show, so a failure at the provider
     * leaves the customer's cart exactly as it was.
     */
    public function store(Request $request)
    {
        $cart = Auth::user()->cart?->load('items.product');

        if (!$cart || $cart->items->isEmpty()) {
            return redirect()->route('cart.index')->with('error', 'Your cart is empty.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30', new CambodianPhone],
            'province' => ['required', 'string', Rule::in(array_keys(Cambodia::provinces()))],
            'district' => ['required', 'string', 'max:120'],
            'commune' => ['required', 'string', 'max:120'],
            'address' => ['required', 'string', 'max:500'],
            'telegram' => ['nullable', 'string', 'max:64', new TelegramHandle],
            'note' => ['nullable', 'string', 'max:500'],
            'save_address' => ['nullable', 'boolean'],
            'payment_method' => ['required', Rule::in([Payment::METHOD_COD, Payment::METHOD_KHQR])],
        ], [
            'province.in' => 'Please choose a province from the list.',
            'district.required' => 'Please enter your district (srok/khan).',
            'commune.required' => 'Please enter your commune (khum/sangkat).',
        ]);

        // Re-check stock right before placing the order in case it changed
        foreach ($cart->items as $item) {
            if ($item->quantity > $item->product->stock) {
                return back()
                    ->withErrors(['quantity' => $item->product->name . ' only has ' . $item->product->stock . ' left in stock.'])
                    ->withInput();
            }
        }

        $subtotal = $cart->totalPrice();

        // Re-read the coupon from the database at the moment of purchase: the
        // session only remembers which code, never what it is worth.
        [$coupon, $discount] = $this->resolveCoupon($subtotal, $cart->items);

        // Priced server-side from the province, never from anything the form
        // posted, so the quote in the page cannot be tampered with. Delivery
        // is charged on the pre-discount subtotal, so a coupon cannot also
        // buy free shipping by accident.
        $deliveryFee = Cambodia::deliveryFee($validated['province'], $subtotal);
        $total = round($subtotal - $discount + $deliveryFee, 2);

        if ($validated['payment_method'] === Payment::METHOD_KHQR && $total < 0.01) {
            return back()
                ->withErrors(['payment_method' => 'KHQR payments must be at least $0.01.'])
                ->withInput();
        }

        // Store phone and Telegram in one canonical shape, so "012 345 678"
        // and "+85512345678" are the same customer to us.
        $phone = Cambodia::normalisePhone($validated['phone']);
        $telegram = Cambodia::normaliseTelegram($validated['telegram'] ?? null);

        if ($request->boolean('save_address')) {
            Auth::user()->update([
                'phone' => $phone,
                'telegram' => $telegram,
                'province' => $validated['province'],
                'district' => $validated['district'],
                'commune' => $validated['commune'],
                'address' => $validated['address'],
            ]);
        }

        $order = DB::transaction(function () use ($cart, $validated, $subtotal, $discount, $coupon, $deliveryFee, $total, $phone, $telegram) {
            $order = Order::create([
                'user_id' => Auth::id(),
                'name' => $validated['name'],
                'phone' => $phone,
                'province' => $validated['province'],
                'district' => $validated['district'],
                'commune' => $validated['commune'],
                'address' => $validated['address'],
                'telegram' => $telegram,
                'note' => $validated['note'] ?? null,
                'status' => 'Pending',
                'subtotal' => $subtotal,
                'coupon_code' => $coupon?->code,
                'discount' => $discount,
                'delivery_fee' => $deliveryFee,
                'total' => $total,
            ]);

            // Count the redemption inside the same transaction, so two
            // customers racing for the last use cannot both win it.
            if ($coupon) {
                Coupon::whereKey($coupon->id)->lockForUpdate()->increment('used_count');
            }

            foreach ($cart->items as $item) {
                $order->items()->create([
                    'product_id' => $item->product_id,
                    'product_name' => $item->product->name,
                    // What was actually charged, promotion and all, frozen
                    // onto the order so a later price change cannot rewrite
                    // somebody's receipt.
                    'price' => $item->product->effectivePrice(),
                    'quantity' => $item->quantity,
                ]);

                $item->product->decrement('stock', $item->quantity);
            }

            $order->payment()->create([
                'method' => $validated['payment_method'],
                'status' => Payment::STATUS_PENDING,
                'amount' => $total,
                'currency' => 'USD',
            ]);

            return $order;
        });

        session()->forget(\App\Http\Controllers\CouponController::SESSION_KEY);

        if ($validated['payment_method'] === Payment::METHOD_KHQR) {
            return $this->startKhqrPayment($order, $cart);
        }

        $cart->items()->delete();

        return redirect()->route('orders.show', $order)
            ->with('success', 'Your order has been placed!');
    }

    /**
     * Ask CutLuy for a QR for this order, then send the customer to the pay
     * page. The order stays Pending until a payment.completed webhook arrives.
     */
    protected function startKhqrPayment(Order $order, $cart)
    {
        try {
            $payment = $this->cutluy->createPayment(
                amount: $order->total,
                referenceId: 'order_' . $order->id,
                metadata: [
                    'order_id' => (string) $order->id,
                    'user_id' => (string) $order->user_id,
                ],
                // Derived from the order id, so a double submit or a retry
                // returns the same CutLuy payment instead of creating a second.
                idempotencyKey: 'order_' . $order->id,
            );
        } catch (RateLimitedException $e) {
            $this->abandon($order);

            Log::warning('CutLuy rate limited a payment create.', [
                'order_id' => $order->id,
                'retry_after' => $e->retryAfter,
            ]);

            return back()->withInput()->withErrors([
                'payment_method' => 'KHQR is busy right now. Please try again in '
                    . $e->retryAfter . ' seconds, or choose Cash on Delivery.',
            ]);
        } catch (QuotaExceededException|UnauthorizedException|AccountSuspendedException $e) {
            $this->abandon($order);

            // These three need an operator, not the customer — make them loud.
            Log::critical('KHQR payments are not working: ' . $e->getMessage(), [
                'order_id' => $order->id,
                'error' => $e->errorCode,
                'status' => $e->status,
            ]);

            return back()->withInput()->withErrors([
                'payment_method' => 'KHQR payments are temporarily unavailable. Please choose Cash on Delivery.',
            ]);
        } catch (CutLuyException $e) {
            $this->abandon($order);

            Log::error('Could not create a CutLuy payment: ' . $e->getMessage(), [
                'order_id' => $order->id,
                'error' => $e->errorCode,
                'status' => $e->status,
            ]);

            return back()->withInput()->withErrors([
                'payment_method' => 'We could not start your KHQR payment. Please try again.',
            ]);
        }

        $order->payment->update([
            'cutluy_payment_id' => $payment['id'] ?? null,
            'cutluy_status' => $payment['status'] ?? 'pending',
            'checkout_url' => $payment['checkout_url'] ?? null,
            'qr_string' => $payment['qr_string'] ?? null,
            'amount' => $payment['amount'] ?? $order->total,
            'currency' => $payment['currency'] ?? 'USD',
            'expires_at' => $payment['expires_at'] ?? null,
        ]);

        $cart->items()->delete();

        // Stay on our own site — the pay page renders the KHQR code itself.
        return redirect()->route('payments.khqr', $order);
    }

    /**
     * The coupon held in the session, if it is still usable on this subtotal.
     *
     * An expired or newly disabled code simply falls away rather than blocking
     * checkout, so nobody gets stuck unable to order.
     *
     * @return array{0: ?Coupon, 1: float}
     */
    protected function resolveCoupon(float $subtotal, $items = null): array
    {
        $code = session(\App\Http\Controllers\CouponController::SESSION_KEY);

        if (blank($code)) {
            return [null, 0.0];
        }

        $coupon = Coupon::with('categories', 'products')
            ->where('code', strtoupper(trim($code)))
            ->first();

        if (! $coupon || ! $coupon->isUsableOn($subtotal)) {
            return [null, 0.0];
        }

        // A coupon limited to a category or product only discounts the part
        // of the cart it covers, not the whole order.
        $discount = $items === null
            ? $coupon->discountFor($subtotal)
            : $coupon->discountForCart($items);

        if ($discount <= 0) {
            return [null, 0.0];
        }

        return [$coupon, $discount];
    }

    /**
     * Undo an order whose payment never got started: hand the stock back and
     * drop the order, leaving the cart untouched so the customer can retry.
     */
    protected function abandon(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $order->load('items');
            $order->restoreStock();
            $order->delete();
        });
    }
}
