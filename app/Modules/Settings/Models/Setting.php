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
     * Bounded, not forever: a concurrent set() can race a get() that's
     * already mid-flight reading the OLD row — that reader can still
     * repopulate the cache with stale data right after set() clears it,
     * silently reverting a just-made change (e.g. an admin closing
     * registration) until something else touches this key again. A TTL
     * turns that into a self-healing few-minute drift instead of a
     * permanent one, since rememberForever has no such recovery.
     */
    private const TTL_MINUTES = 5;

    /**
     * Falls back to $default when no row exists — so nothing needs to
     * be seeded on day one, and a setting this app hasn't started
     * exposing to the UI yet still behaves like its config() default.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember(self::cacheKey($key), now()->addMinutes(self::TTL_MINUTES), function () use ($key, $default) {
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

        // Writes the fresh value through directly rather than only
        // forgetting the key — forgetting alone leaves the exact race
        // window described above open on every write, not just some.
        Cache::put(self::cacheKey($key), $value, now()->addMinutes(self::TTL_MINUTES));
    }
}
