<?php

namespace App\Models;

use App\Support\Cambodia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Order extends Model
{
    protected $fillable = [
        'user_id',
        'order_number',
        'name',
        'phone',
        'province',
        'district',
        'commune',
        'address',
        'telegram',
        'note',
        'status',
        'hidden_at',
        'subtotal',
        'coupon_code',
        'discount',
        'delivery_fee',
        'total',
    ];

    /** Stamped on the front of every order reference. */
    public const NUMBER_PREFIX = 'SM';

    protected static function booted(): void
    {
        // Numbered on the way in, so nothing can save an order without one.
        static::creating(function (self $order) {
            $order->order_number ??= static::nextNumber();
        });
    }

    /**
     * A reference nothing else is using.
     *
     * Four random digits is only ten thousand a day, so on a busy day two
     * orders will eventually draw the same one. Rather than let that surface
     * as a failed checkout, try again, and widen the tail if the day really is
     * that crowded.
     */
    public static function nextNumber(?\DateTimeInterface $date = null): string
    {
        foreach ([4, 4, 4, 4, 4, 6, 6, 8] as $digits) {
            $number = static::makeNumber($date, $digits);

            if (! static::where('order_number', $number)->exists()) {
                return $number;
            }
        }

        // Astronomically unlikely; a timestamp tail is unique enough to fall
        // back on rather than throwing at the customer.
        return sprintf('%s-%s-%s', static::NUMBER_PREFIX,
            ($date ? Carbon::instance($date) : now())->format('ymd'),
            now()->format('His').random_int(0, 9));
    }

    /**
     * Build a reference like SM-260918-4821.
     *
     * The tail is random rather than sequential: a counter in the reference
     * would give away the shop's volume just as the row id did, and would let
     * one customer guess another's order.
     */
    public static function makeNumber(?\DateTimeInterface $date = null, int $digits = 4): string
    {
        $date = $date ? Carbon::instance($date) : now();

        return sprintf(
            '%s-%s-%0'.$digits.'d',
            static::NUMBER_PREFIX,
            $date->format('ymd'),
            random_int(0, (10 ** $digits) - 1)
        );
    }

    /**
     * Orders are addressed by their reference, never by the row id, so no URL
     * exposes the shop's running total either.
     */
    public function getRouteKeyName(): string
    {
        return 'order_number';
    }

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'hidden_at' => 'datetime',
            'discount' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    /**
     * The delivery address written the way it is read in Cambodia:
     * most specific first, province last.
     */
    public function fullAddress(): string
    {
        return collect([
            $this->address,
            $this->commune,
            $this->district,
            $this->province,
        ])->filter()->implode(', ');
    }

    /**
     * A short destination for lists and summaries.
     *
     * Orders placed before the address was captured province-by-province have
     * only the old free-text field, so fall back to its first line rather than
     * printing a label with nothing after it.
     */
    public function destination(): string
    {
        if (filled($this->province)) {
            return $this->province;
        }

        $first = trim(explode('
', (string) $this->address)[0]);

        return $first !== '' ? $first : '—';
    }

    /**
     * May the customer call this order off themselves?
     *
     * Only while nothing has been paid and nobody in the shop has started
     * working on it. Once it is Confirmed the shop is picking the items, so
     * cancelling becomes a conversation rather than a button.
     */
    public function isCancellableByCustomer(): bool
    {
        return $this->status === 'Pending'
            && ! ($this->payment?->isPaid() ?? false);
    }

    /**
     * Work the totals out again from whatever items are left.
     *
     * Dropping an item changes more than the subtotal: delivery is priced from
     * the subtotal too, so an order that fell under Phnom Penh's free
     * threshold starts paying for delivery again, and a coupon with a minimum
     * spend may no longer apply.
     *
     * The coupon's redemption count is deliberately left alone — the same as
     * when an order is cancelled outright — so the two paths behave alike.
     */
    public function reprice(): void
    {
        $this->load('items.product');

        $subtotal = round($this->items->sum(fn ($item) => $item->subtotal()), 2);

        [$code, $discount] = $this->recheckCoupon($subtotal);

        $delivery = Cambodia::deliveryFee($this->province, $subtotal);

        $this->update([
            'subtotal' => $subtotal,
            'coupon_code' => $code,
            'discount' => $discount,
            'delivery_fee' => $delivery,
            'total' => round($subtotal - $discount + $delivery, 2),
        ]);
    }

    /**
     * Is this order's coupon still worth anything to what remains?
     *
     * @return array{0: ?string, 1: float}
     */
    protected function recheckCoupon(float $subtotal): array
    {
        if (blank($this->coupon_code)) {
            return [null, 0.0];
        }

        $coupon = Coupon::with('categories', 'products')
            ->where('code', $this->coupon_code)
            ->first();

        if (! $coupon || ! $coupon->isUsableOn($subtotal)) {
            return [null, 0.0];
        }

        $discount = $coupon->discountForCart($this->items);

        return $discount > 0 ? [$coupon->code, $discount] : [null, 0.0];
    }

    /**
     * Nothing more will happen to this order.
     */
    public function isFinished(): bool
    {
        return in_array($this->status, ['Delivered', 'Cancelled'], true);
    }

    public function formattedPhone(): ?string
    {
        return Cambodia::formatPhone($this->phone);
    }

    public const STATUSES = [
        'Pending',
        'Confirmed',
        'Preparing',
        'Out for Delivery',
        'Delivered',
        'Cancelled',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The lines this order is actually priced on.
     */
    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Every line it has ever had, including any the customer took off.
     */
    public function allItems()
    {
        return $this->hasMany(OrderItem::class)->withTrashed();
    }

    /**
     * What to show on a card or a receipt.
     *
     * Normally the live lines. An order emptied line by line has none left,
     * so fall back to what it was — better than a card showing nothing.
     */
    public function displayItems()
    {
        $live = $this->items;

        return $live->isNotEmpty() ? $live : $this->allItems;
    }

    public function payment()
    {
        return $this->hasOne(Payment::class);
    }

    /**
     * Put the reserved stock back on the shelf.
     *
     * Stock is decremented when the order is created, before a KHQR payment
     * has actually been made, so an unpaid order has to hand it back when it
     * expires, fails, or never gets off the ground. Callers are responsible
     * for only invoking this once per order (guard on the status change).
     */
    public function restoreStock(): void
    {
        foreach ($this->items as $item) {
            if ($item->product_id) {
                Product::whereKey($item->product_id)->increment('stock', $item->quantity);
            }
        }
    }

    /**
     * Bootstrap badge color for the current status, used in views.
     */
    public function statusColor(): string
    {
        return match ($this->status) {
            'Pending' => 'warning',
            'Confirmed' => 'info',
            'Preparing' => 'primary',
            'Out for Delivery' => 'secondary',
            'Delivered' => 'success',
            'Cancelled' => 'danger',
            default => 'light',
        };
    }
}
