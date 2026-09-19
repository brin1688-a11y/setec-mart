<?php

namespace App\Models;

use App\Support\Cambodia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A delivery address the customer has saved.
 *
 * Each one is a complete drop-off — name, phone and the Cambodian
 * province → district → commune → street chain — because the person taking
 * delivery at the office is often not the one who lives at home.
 */
class Address extends Model
{
    protected $fillable = [
        'user_id',
        'label',
        'name',
        'phone',
        'telegram',
        'province',
        'district',
        'commune',
        'address',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Written the way it is read in Cambodia: most specific first.
     */
    public function full(): string
    {
        return collect([$this->address, $this->commune, $this->district, $this->province])
            ->filter()
            ->implode(', ');
    }

    public function formattedPhone(): ?string
    {
        return Cambodia::formatPhone($this->phone);
    }

    /**
     * What delivery costs to here, at this basket size.
     */
    public function deliveryFee(float $subtotal = 0.0): float
    {
        return Cambodia::deliveryFee($this->province, $subtotal);
    }

    public function deliveryEta(): string
    {
        return Cambodia::deliveryEta($this->province);
    }

    /**
     * Make this the one checkout reaches for, and demote the rest.
     *
     * Done in one transaction so a customer double-clicking cannot end up
     * with two defaults, or none.
     */
    public function makeDefault(): void
    {
        DB::transaction(function () {
            static::where('user_id', $this->user_id)
                ->where('id', '!=', $this->id)
                ->update(['is_default' => false]);

            $this->update(['is_default' => true]);
        });
    }
}
