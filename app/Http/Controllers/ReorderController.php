<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Order;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Put a past order back in the basket.
 *
 * Deliberately not a copy of the old order: prices move, products get taken
 * off the shelf and stock runs out. This refills the cart from what is on
 * sale today and says plainly what could not be brought back, rather than
 * quietly dropping it.
 */
class ReorderController extends Controller
{
    public function __invoke(Order $order)
    {
        if ($order->user_id !== Auth::id()) {
            abort(403);
        }

        // Live lines only — something the customer removed last time should
        // not reappear.
        $order->load('items.product');

        $added = [];
        $unavailable = [];

        DB::transaction(function () use ($order, &$added, &$unavailable) {
            $cart = Cart::firstOrCreate(['user_id' => Auth::id()]);

            foreach ($order->items as $item) {
                $product = $item->product;

                if (! $product || ! $product->status) {
                    $unavailable[] = $item->product_name.' (no longer sold)';

                    continue;
                }

                if ($product->stock < 1) {
                    $unavailable[] = $product->name.' (out of stock)';

                    continue;
                }

                // Never put more in the basket than the shelf holds.
                $wanted = min($item->quantity, $product->stock);

                $line = $cart->items()->where('product_id', $product->id)->first();

                if ($line) {
                    $line->update(['quantity' => min($line->quantity + $wanted, $product->stock)]);
                } else {
                    $cart->items()->create(['product_id' => $product->id, 'quantity' => $wanted]);
                }

                $added[] = $product->name.($wanted < $item->quantity ? " (only {$wanted} left)" : '');
            }
        });

        if ($added === []) {
            return back()->with('error', $unavailable === []
                ? 'There was nothing on that order to bring back.'
                : 'Nothing from that order is available right now: '.implode(', ', $unavailable));
        }

        $message = count($added).' '.Str::plural('item', count($added)).' back in your cart.';

        if ($unavailable !== []) {
            $message .= ' We could not add: '.implode(', ', $unavailable).'.';
        }

        return redirect()->route('cart.index')->with('success', $message);
    }
}
