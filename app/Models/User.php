<?php

namespace App\Models;

use App\Notifications\QueuedResetPassword;
use App\Notifications\QueuedVerifyEmail;
use App\Support\Cambodia;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    /**
     * Signing in through Google is itself proof of the address, so those
     * accounts never need to be sent a verification link.
     */
    public function signsInWithGoogle(): bool
    {
        return $this->google_id !== null;
    }

    /**
     * Both of these go through the queue so a slow or unreachable mail
     * provider never turns a signup or a reset request into an error.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new QueuedVerifyEmail);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new QueuedResetPassword($token));
    }

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'locale',
        'profile_picture',
        'google_id',
        'email_verified_at',
        'phone',
        'telegram',
        'province',
        'district',
        'commune',
        'address',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * The user's shopping cart.
     */
    public function cart()
    {
        return $this->hasOne(Cart::class);
    }

    /**
     * The user's order history.
     */
    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Whether this user has admin privileges.
     */
    /**
     * May this account buy things?
     *
     * Staff browse the storefront but never order from it — see
     * DenyShoppingToStaff for why.
     */
    /**
     * The phone as it is written in Cambodia — "012 345 678" — from the
     * digits-only form it is stored in. Orders and addresses do the same.
     */
    public function formattedPhone(): ?string
    {
        return Cambodia::formatPhone($this->phone);
    }

    /**
     * Every delivery address this customer has saved, the default first.
     */
    public function addresses()
    {
        return $this->hasMany(Address::class)->orderByDesc('is_default')->orderBy('label');
    }

    /**
     * The one checkout should reach for.
     *
     * Falls back to whichever exists if none is flagged, so a customer who
     * somehow has no default still gets their address filled in.
     */
    public function defaultAddress(): ?Address
    {
        return $this->addresses()->where('is_default', true)->first()
            ?? $this->addresses()->first();
    }

    /**
     * Loyalty points, worked out from what has actually been spent.
     *
     * One point per dollar on orders that were not cancelled. Derived rather
     * than stored: a column would drift the first time an order is cancelled
     * or re-priced, and nobody would notice.
     */
    public function loyaltyPoints(): int
    {
        return (int) floor(
            $this->orders()->where('status', '!=', 'Cancelled')->sum('total')
        );
    }

    public function canShop(): bool
    {
        return ! $this->isAdmin();
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * A usable URL for this user's avatar.
     *
     * profile_picture holds either a full URL (the picture Google gave us) or
     * a path on the "public" disk (a file they uploaded themselves), the same
     * way Product::imageUrl() works.
     */
    public function avatarUrl(): string
    {
        if (blank($this->profile_picture)) {
            return asset('images/default-avatar.svg');
        }

        if (str_starts_with($this->profile_picture, 'http://')
            || str_starts_with($this->profile_picture, 'https://')) {
            return $this->profile_picture;
        }

        return asset('storage/'.$this->profile_picture);
    }

    /**
     * Signs in through Google and has never set a password of their own.
     */
    /**
     * Whether this customer has a delivery address saved to prefill from.
     */
    public function hasSavedAddress(): bool
    {
        return filled($this->province) && filled($this->address);
    }

    public function usesGoogleOnly(): bool
    {
        return filled($this->google_id) && blank($this->password);
    }
}
