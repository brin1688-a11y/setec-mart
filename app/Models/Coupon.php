<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    public const TYPE_PERCENT = 'percent';

    public const TYPE_FIXED = 'fixed';

    public const TYPES = [self::TYPE_PERCENT, self::TYPE_FIXED];

    protected $fillable = [
        'code',
        'description',
        'type',
        'value',
        'min_subtotal',
        'max_discount',
        'max_uses',
        'starts_at',
        'expires_at',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'min_subtotal' => 'decimal:2',
            'max_discount' => 'decimal:2',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'active' => 'boolean',
        ];
    }

    /**
     * Codes are matched case-insensitively by storing them upper-cased.
     */
    public function setCodeAttribute(?string $value): void
    {
        $this->attributes['code'] = $value === null ? null : strtoupper(trim($value));
    }

    public static function findByCode(?string $code): ?self
    {
        if (blank($code)) {
            return null;
        }

        return static::where('code', strtoupper(trim($code)))->first();
    }

    /**
     * Why this coupon cannot be used right now, or null when it can.
     *
     * Returns a reason rather than a bare bool so the checkout can tell the
     * customer what is actually wrong instead of "invalid code".
     */
    /**
     * Coupons that are switched on, inside their dates and not used up.
     *
     * The query-side twin of reasonUnusable(), minus the minimum-spend check,
     * which needs a basket to judge. Used where coupons are listed rather than
     * applied — offering a code that checkout will refuse is worse than
     * offering none.
     */
    public function scopeLive($query)
    {
        return $query
            ->where('active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now()))
            ->where(fn ($q) => $q->whereNull('max_uses')->orWhereColumn('used_count', '<', 'max_uses'));
    }

    public function reasonUnusable(float $subtotal): ?string
    {
        if (! $this->active) {
            return __('site.coupon.inactive');
        }

        if ($this->starts_at && $this->starts_at->isFuture()) {
            return __('site.coupon.not_started');
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return __('site.coupon.expired');
        }

        if ($this->max_uses !== null && $this->used_count >= $this->max_uses) {
            return __('site.coupon.used_up');
        }

        if ($subtotal < (float) $this->min_subtotal) {
            return __('site.coupon.min_subtotal', [
                'amount' => '$'.number_format((float) $this->min_subtotal, 2),
            ]);
        }

        return null;
    }

    public function isUsableOn(float $subtotal): bool
    {
        return $this->reasonUnusable($subtotal) === null;
    }

    public function categories()
    {
        return $this->belongsToMany(Category::class);
    }

    public function products()
    {
        return $this->belongsToMany(Product::class);
    }

    /**
     * A coupon with no categories and no products attached applies to the
     * whole cart; otherwise it only discounts the items it names.
     */
    public function appliesToEverything(): bool
    {
        return $this->categories()->doesntExist() && $this->products()->doesntExist();
    }

    /**
     * Whether this particular product is covered by the coupon.
     */
    public function covers(Product $product): bool
    {
        if ($this->appliesToEverything()) {
            return true;
        }

        return $this->products->contains('id', $product->id)
            || $this->categories->contains('id', $product->category_id);
    }

    /**
     * The part of a cart this coupon is allowed to discount.
     *
     * @param  iterable<object>  $items  Cart items, each with ->product and ->subtotal()
     */
    public function eligibleSubtotal(iterable $items): float
    {
        $this->loadMissing('categories', 'products');

        if ($this->appliesToEverything()) {
            $total = 0.0;
            foreach ($items as $item) {
                $total += $item->subtotal();
            }

            return round($total, 2);
        }

        $total = 0.0;

        foreach ($items as $item) {
            if ($item->product && $this->covers($item->product)) {
                $total += $item->subtotal();
            }
        }

        return round($total, 2);
    }

    /**
     * What this coupon takes off, given the cart it is applied to.
     *
     * @param  iterable<object>  $items
     */
    public function discountForCart(iterable $items): float
    {
        return $this->discountFor($this->eligibleSubtotal($items));
    }

    /**
     * What this coupon takes off the given (already eligible) amount.
     *
     * Never more than that amount itself — a discount must not turn into
     * store credit or a negative total.
     */
    public function discountFor(float $subtotal): float
    {
        if ($subtotal <= 0) {
            return 0.0;
        }

        $discount = $this->type === self::TYPE_PERCENT
            ? $subtotal * ((float) $this->value / 100)
            : (float) $this->value;

        if ($this->max_discount !== null) {
            $discount = min($discount, (float) $this->max_discount);
        }

        return round(min($discount, $subtotal), 2);
    }

    /**
     * "All products", or what it is limited to, for the admin list.
     */
    public function scopeLabel(): string
    {
        $this->loadMissing('categories', 'products');

        if ($this->appliesToEverything()) {
            return 'All products';
        }

        $parts = [];

        if ($this->categories->isNotEmpty()) {
            $parts[] = $this->categories->pluck('name')->implode(', ');
        }

        if ($this->products->isNotEmpty()) {
            $parts[] = $this->products->count().' '.str('product')->plural($this->products->count());
        }

        return implode(' + ', $parts);
    }

    /**
     * "10% off" / "$5 off", for lists and the checkout summary.
     */
    public function label(): string
    {
        return $this->type === self::TYPE_PERCENT
            ? rtrim(rtrim(number_format((float) $this->value, 2), '0'), '.').'% off'
            : '$'.number_format((float) $this->value, 2).' off';
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isExhausted(): bool
    {
        return $this->max_uses !== null && $this->used_count >= $this->max_uses;
    }

    /**
     * A short status word for the admin list.
     */
    public function statusLabel(): string
    {
        return match (true) {
            ! $this->active => 'Disabled',
            $this->isExpired() => 'Expired',
            $this->isExhausted() => 'Used up',
            $this->starts_at && $this->starts_at->isFuture() => 'Scheduled',
            default => 'Active',
        };
    }

    public function statusColor(): string
    {
        return match ($this->statusLabel()) {
            'Active' => 'success',
            'Scheduled' => 'info',
            'Expired', 'Used up' => 'secondary',
            default => 'warning',
        };
    }
}
