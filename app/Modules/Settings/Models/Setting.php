<?php

declare(strict_types=1);

namespace App\Modules\Settings\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * Key-value store for platform settings an admin can change from the
 * UI at runtime — a JSON `value` column instead of one typed column
 * per setting means a future setting never needs its own migration.
 */
final class Setting extends Model
{
    protected $fillable = ['key', 'value', 'updated_by'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    private static function cacheKey(string $key): string
    {
        return 'setting:'.$key;
    }

    /**
     * Falls back to $default when no row exists — so nothing needs to
     * be seeded on day one, and a setting this app hasn't started
     * exposing to the UI yet still behaves like its config() default.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::rememberForever(self::cacheKey($key), function () use ($key, $default) {
            $setting = self::query()->where('key', $key)->first();

            return $setting?->value ?? $default;
        });
    }

    public static function set(string $key, mixed $value, ?int $updatedBy = null): void
    {
        self::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_by' => $updatedBy],
        );

        Cache::forget(self::cacheKey($key));
    }
}
