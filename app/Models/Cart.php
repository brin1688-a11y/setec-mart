<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{
    protected $fillable = [
        'user_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(CartItem::class);
    }

    /**
     * Total price of all items currently in the cart.
     */
    public function totalPrice(): float
    {
        return $this->items->sum(fn ($item) => $item->subtotal());
    }

    /**
     * Total number of units in the cart (not just line count).
     */
    public function totalItems(): int
    {
        return (int) $this->items->sum('quantity');
    }
}