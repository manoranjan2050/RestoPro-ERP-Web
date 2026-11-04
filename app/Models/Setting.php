<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/** Simple key/value business settings, cached for the request lifecycle and 1 hour across requests. */
class Setting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['key', 'value'];

    public const DEFAULTS = [
        'business_name' => 'My Restaurant',
        'currency_symbol' => '₹',
        'fy_start_month' => '4',
    ];

    public static function allCached(): array
    {
        // Must never throw: routes/console.php reads settings on every artisan command, including the very first
        // `php artisan migrate` on a fresh database where neither the cache table nor the settings table exists yet.
        try {
            return Cache::remember('settings.all', 3600, fn () => static::query()->pluck('value', 'key')->all() + self::DEFAULTS);
        } catch (\Throwable) {
            try {
                return static::query()->pluck('value', 'key')->all() + self::DEFAULTS;
            } catch (\Throwable) {
                return self::DEFAULTS; // before migrations run
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return static::allCached()[$key] ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget('settings.all');
    }

    /** Store a secret (API token etc.) encrypted with APP_KEY. Empty value removes it. */
    public static function putSecret(string $key, ?string $value): void
    {
        static::put($key, $value === null || $value === '' ? null : \Illuminate\Support\Facades\Crypt::encryptString($value));
    }

    public static function secret(string $key): ?string
    {
        $v = static::get($key);
        if (! $v) {
            return null;
        }
        try {
            return \Illuminate\Support\Facades\Crypt::decryptString($v);
        } catch (\Throwable) {
            return null; // APP_KEY changed — the secret must be entered again
        }
    }
}
