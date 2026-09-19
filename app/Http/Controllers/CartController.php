<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CartController extends Controller
{
    /**
     * Get (or create) the logged-in user's cart.
     */
    protected function currentCart(): Cart
    {
        return Cart::firstOrCreate(['user_id' => Auth::id()]);
    }

    /**
     * Show the cart page.
     */
    public function index()
    {
        $cart = $this->currentCart()->load('items.product.category');

        return view('cart.index', compact('cart'));
    }

    /**
     * Add a product to the cart (or increase quantity if it's already there).
     */
    public function add(Request $request, Product $product)
    {
        $request->validate([
            'quantity' => ['nullable', 'integer', 'min:1'],
        ]);

        $quantity = (int) $request->input('quantity', 1);

        $cart = $this->currentCart();

        $item = $cart->items()->where('product_id', $product->id)->first();

        if ($item) {
            $item->increment('quantity', $quantity);
        } else {
            $cart->items()->create([
                'product_id' => $product->id,
                'quantity' => $quantity,
            ]);
        }

        // "Buy Now" is the same add, it just skips the cart page.
        if ($request->input('action') === 'buy_now') {
            return redirect()->route('checkout.index');
        }

        return back()->with('success', $product->name . ' added to cart.');
    }

    /**
     * Update the quantity of a cart line item.
     */
    public function update(Request $request, CartItem $cartItem)
    {
        $this->authorizeItem($cartItem);

        $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $cartItem->update(['quantity' => $request->quantity]);

        return back()->with('success', 'Cart updated.');
    }

    /**
     * Remove a line item from the cart.
     */
    public function remove(CartItem $cartItem)
    {
        $this->authorizeItem($cartItem);

        $cartItem->delete();

        return back()->with('success', 'Item removed from cart.');
    }

    /**
     * Make sure the cart item actually belongs to the logged-in user.
     */
    protected function authorizeItem(CartItem $cartItem): void
    {
        if ($cartItem->cart->user_id !== Auth::id()) {
            abort(403);
        }
    }
}