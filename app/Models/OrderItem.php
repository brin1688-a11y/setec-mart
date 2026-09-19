<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrderItem extends Model
{
    /**
     * Taking a line off an unpaid order hides it from the totals rather than
     * erasing it — an order is a record of what was asked for, and a
     * cancelled one with no lines at all tells nobody anything.
     */
    use SoftDeletes;

    protected $fillable = [
        'order_id',
        'product_id',
        'product_name',
        'price',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            // Money, like everywhere else on an order — two places, as stored.
            'price' => 'decimal:2',
        ];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function subtotal(): float
    {
        return $this->quantity * $this->price;
    }
}
