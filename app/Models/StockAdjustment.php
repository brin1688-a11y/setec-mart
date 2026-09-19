<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockAdjustment extends Model
{
    protected $fillable = [
        'product_id',
        'user_id',
        'type',
        'quantity_change',
        'stock_after',
        'reason',
    ];

    const TYPES = ['restock', 'correction', 'damage', 'return'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function typeColor(): string
    {
        return match($this->type) {
            'restock' => 'success',
            'correction' => 'secondary',
            'damage' => 'danger',
            'return' => 'info',
            default => 'secondary',
        };
    }
}