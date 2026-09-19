<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * A shop setting the owner can change from the admin area.
 *
 * Read through the cache: these are consulted on nearly every page — the
 * storefront footer, checkout, the assistant — and they change perhaps a few
 * times a year.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    protected const CACHE_KEY = 'settings.all';

    /**
     * Every saved setting, keyed by name.
     *
     * @return array<string, mixed>
     */
    public static function all_cached(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => static::query()->pluck('value', 'key')->all());
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all_cached()[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Drop the cached copy — used by tests and after a direct write.
     */
    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
