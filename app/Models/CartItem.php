<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CartItem extends Model
{
    protected $fillable = [
        'cart_id',
        'product_id',
        'quantity',
    ];

    public function cart()
    {
        return $this->belongsTo(Cart::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * What this line costs today, promotion included.
     */
    public function subtotal(): float
    {
        return round($this->quantity * $this->unitPrice(), 2);
    }

    /**
     * The price one of these is going for right now.
     *
     * Read from the product every time rather than stored on the line: a
     * promotion that starts or ends while the cart sits there should change
     * what the customer is quoted, and the order records the price for good
     * at checkout.
     */
    public function unitPrice(): float
    {
        return $this->product?->effectivePrice() ?? 0.0;
    }
}
