<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Payment extends Model
{
    public const METHOD_COD = 'cod';

    public const METHOD_KHQR = 'khqr';

    public const STATUS_PENDING = 'Pending';

    public const STATUS_PAID = 'Paid';

    public const STATUS_EXPIRED = 'Expired';

    public const STATUS_FAILED = 'Failed';

    /** The customer called the order off before paying. */
    public const STATUS_CANCELLED = 'Cancelled';

    protected $fillable = [
        'order_id',
        'method',
        'status',
        'cutluy_payment_id',
        'cutluy_status',
        'renewals',
        'amount',
        'currency',
        'checkout_url',
        'qr_string',
        'paid_at',
        'expires_at',
        'last_event_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_event_at' => 'datetime',
        ];
    }

    /**
     * CutLuy quotes the QR's expiry in UTC ("2026-09-18T02:25:58Z"), but the
     * shop's clock is Phnom Penh time. Eloquent's datetime cast keeps the
     * wall-clock and throws the offset away, so an untouched value lands seven
     * hours in the past and every fresh QR reads as already expired.
     *
     * Convert on the way in — the one gate every caller passes through. A value
     * with no offset is already ours and is left alone.
     */
    public function setExpiresAtAttribute($value): void
    {
        $this->attributes['expires_at'] = blank($value)
            ? null
            : Carbon::parse($value)
                ->setTimezone(config('app.timezone'))
                ->format('Y-m-d H:i:s');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * What the customer sees. CutLuy is the provider we happen to route
     * through — that belongs in the logs, not on the order page.
     */
    public function methodLabel(): string
    {
        return match ($this->method) {
            self::METHOD_COD => 'Cash on Delivery',
            self::METHOD_KHQR => 'KHQR',
            'bank_transfer' => 'Bank Transfer',
            default => ucfirst($this->method),
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            self::STATUS_PAID => 'success',
            self::STATUS_EXPIRED, self::STATUS_FAILED => 'danger',
            self::STATUS_CANCELLED => 'secondary',
            default => 'warning',
        };
    }

    /**
     * Nothing has been taken and nothing is owed — the customer stopped here.
     */
    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    /**
     * A KHQR payment the customer can still complete.
     */
    public function isAwaitingKhqr(): bool
    {
        return $this->method === self::METHOD_KHQR
            && in_array($this->status, [self::STATUS_PENDING], true)
            && filled($this->cutluy_payment_id);
    }

    /**
     * The QR's few minutes are up.
     *
     * A payment with no expiry has not been given one by CutLuy, which we
     * treat as still good rather than inventing a deadline.
     */
    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Can we ask CutLuy for a fresh QR against this order?
     *
     * Only while the money has genuinely not moved and nothing has released
     * the order: once an expiry has been processed the stock is back on the
     * shelf, and re-opening payment would sell what we no longer hold.
     */
    public function canRenewQr(): bool
    {
        return $this->method === self::METHOD_KHQR
            && $this->status === self::STATUS_PENDING
            && $this->order?->status === 'Pending'
            && $this->renewals < self::MAX_RENEWALS;
    }

    /** A guard against a stuck page quietly minting QR after QR. */
    public const MAX_RENEWALS = 10;
}
