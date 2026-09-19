<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * Cambodian address and delivery helpers.
 *
 * Everything the checkout, the order pages and the admin need to agree on
 * about provinces, delivery zones and phone numbers lives here, so the fee a
 * customer is quoted is computed by exactly the same code that charges them.
 */
class Cambodia
{
    /**
     * @return array<string, array{km: string, zone: string}>
     */
    public static function provinces(): array
    {
        return config('cambodia.provinces', []);
    }

    /**
     * The province name as shown to the customer.
     *
     * The Khmer spelling is still kept in config for reference, but the
     * interface is English-only now.
     */
    public static function label(string $english): string
    {
        return $english;
    }

    /**
     * Province options for a <select>, in the current language only —
     * showing both at once ("Phnom Penh — ភ្នំពេញ") just makes the list
     * harder to scan.
     *
     * @return array<string, string>
     */
    public static function provinceOptions(): array
    {
        $options = [];

        foreach (array_keys(static::provinces()) as $english) {
            $options[$english] = static::label($english);
        }

        // Alphabetical, because the customer is scanning for a name rather
        // than thinking about delivery zones.
        asort($options);

        return $options;
    }

    /**
     * The same options grouped by delivery zone, so a 25-item list becomes
     * three short ones and the customer can see what each tier costs before
     * they choose.
     *
     * @return array<string, array<string, string>>
     */
    public static function provinceGroups(): array
    {
        $groups = [];

        foreach (static::provinces() as $english => $meta) {
            $groups[$meta['zone']][$english] = static::label($english);
        }

        // Cheapest first, which is also nearest.
        return array_replace(array_flip(['city', 'near', 'far']), $groups);
    }

    public static function isProvince(?string $province): bool
    {
        return $province !== null && array_key_exists($province, static::provinces());
    }

    public static function khmerName(?string $province): ?string
    {
        return static::provinces()[$province]['km'] ?? null;
    }

    public static function zone(?string $province): string
    {
        // An unknown province is priced as the most distant, never the cheapest.
        return static::provinces()[$province]['zone'] ?? 'far';
    }

    /**
     * Everything configured for the zone a province falls in.
     *
     * @return array{label: string, fee: float, free_over: float|null, eta: string, eta_days: int}
     */
    /**
     * The delivery zones as they stand right now.
     *
     * The shop can change prices and lead times from the admin area, so this
     * is the one place that decides what they are: whatever has been saved,
     * falling back to config/cambodia.php for anything that has not. Every
     * caller goes through here rather than reading the config directly, or a
     * price change would reach some pages and not others.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function zones(): array
    {
        $defaults = config('cambodia.delivery.zones', []);
        $saved = Setting::get('delivery.zones', []);

        if (! is_array($saved) || $saved === []) {
            return $defaults;
        }

        foreach ($defaults as $key => $zone) {
            if (isset($saved[$key]) && is_array($saved[$key])) {
                $defaults[$key] = array_merge($zone, $saved[$key]);
            }
        }

        return $defaults;
    }

    public static function zoneRules(?string $province): array
    {
        $zone = static::zone($province);

        return static::zones()[$zone] ?? [
            'label' => ucfirst($zone), 'fee' => 0.0, 'free_over' => null,
            'eta' => '', 'eta_days' => 0,
        ];
    }

    public static function zoneLabel(?string $province): string
    {
        return static::zoneRules($province)['label'];
    }

    /**
     * How long delivery takes to this province, in words.
     */
    public static function deliveryEta(?string $province): string
    {
        return static::zoneRules($province)['eta'];
    }

    /**
     * The date an order placed now should arrive by.
     */
    public static function estimatedArrival(?string $province, ?\DateTimeInterface $from = null): Carbon
    {
        $days = (int) static::zoneRules($province)['eta_days'];

        return Carbon::instance(
            $from ? Carbon::instance($from) : now()
        )->addDays($days);
    }

    /**
     * What delivery costs for this province at this order subtotal.
     *
     * Each zone has its own free threshold, so Phnom Penh can ship free over
     * $20 while the provinces always pay.
     */
    public static function deliveryFee(?string $province, float $subtotal = 0.0): float
    {
        $rules = static::zoneRules($province);

        if ($rules['free_over'] !== null && $subtotal >= (float) $rules['free_over']) {
            return 0.0;
        }

        return round((float) $rules['fee'], 2);
    }

    public static function shipsFreeAt(?string $province): ?float
    {
        $over = static::zoneRules($province)['free_over'];

        return $over === null ? null : (float) $over;
    }

    /**
     * How much more this customer must spend for free delivery to their
     * province, or null when it is already free or that zone never ships free.
     */
    public static function amountToFreeDelivery(float $subtotal, ?string $province = null): ?float
    {
        // With no province chosen yet, quote the best case so the storefront
        // can still advertise the offer.
        $freeOver = $province === null
            ? static::bestFreeThreshold()
            : static::shipsFreeAt($province);

        if ($freeOver === null || $subtotal >= $freeOver) {
            return null;
        }

        return round($freeOver - $subtotal, 2);
    }

    /**
     * The lowest free-delivery threshold any zone offers.
     */
    public static function bestFreeThreshold(): ?float
    {
        $thresholds = collect(static::zones())
            ->pluck('free_over')
            ->filter(fn ($v) => $v !== null)
            ->map(fn ($v) => (float) $v);

        return $thresholds->isEmpty() ? null : $thresholds->min();
    }

    /**
     * The cheapest delivery any zone charges, for "delivery from $X" copy.
     */
    public static function cheapestFee(): float
    {
        return (float) collect(static::zones())->pluck('fee')->min();
    }

    /**
     * Normalise a Cambodian number to local 0-prefixed form: +855 12 345 678
     * and 012345678 both become 012345678, so duplicates match.
     */
    public static function normalisePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $trimmed = preg_replace('/[\s\-().]/', '', $phone);

        if (! preg_match(config('cambodia.phone_regex'), $trimmed, $matches)) {
            return null;
        }

        return '0'.$matches[1];
    }

    public static function isValidPhone(?string $phone): bool
    {
        return static::normalisePhone($phone) !== null;
    }

    /**
     * Group the digits the way Cambodians read them: 012 345 678.
     */
    public static function formatPhone(?string $phone): ?string
    {
        $local = static::normalisePhone($phone);

        if ($local === null) {
            return $phone;
        }

        $rest = substr($local, 3);

        return trim(substr($local, 0, 3).' '.implode(' ', str_split($rest, 3)));
    }

    /**
     * A Telegram handle without the @, or null if it is not usable.
     */
    public static function normaliseTelegram(?string $handle): ?string
    {
        if (blank($handle)) {
            return null;
        }

        $clean = ltrim(trim($handle), '@');
        // Accept a pasted t.me link too.
        $clean = preg_replace('#^(?:https?://)?t\.me/#i', '', $clean);

        return preg_match('/^[A-Za-z0-9_]{5,32}$/', $clean) ? $clean : null;
    }
}
