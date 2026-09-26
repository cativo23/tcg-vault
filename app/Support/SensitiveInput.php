<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Masks secrets a visitor typed before request data is kept anywhere
 * outside the database — Telescope's request entries and the error
 * reports sent to Bugsink.
 *
 * Every form in this app is Livewire, so a typed password rarely arrives
 * as a top-level `password` field: it sits under a dotted update key
 * (`components.0.updates."form.password"`) and inside each component's
 * `snapshot`, which is itself a JSON string. Both are walked here.
 */
final class SensitiveInput
{
    public const MASK = '********';

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function scrub(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $data[$key] = $value === '' || $value === null ? $value : self::MASK;
            } elseif (is_array($value)) {
                $data[$key] = self::scrub($value);
            } elseif ($key === 'snapshot' && is_string($value)) {
                $data[$key] = self::scrubJson($value);
            }
        }

        return $data;
    }

    /**
     * Judged on the last segment of a dotted Livewire key, so
     * `form.password` matches but `notes` or `tokens_used` don't.
     */
    private static function isSensitiveKey(string $key): bool
    {
        $name = strtolower((string) last(explode('.', $key)));

        return str_contains($name, 'password')
            || str_contains($name, 'secret')
            || $name === 'token';
    }

    private static function scrubJson(string $json): string
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return $json;
        }

        return (string) json_encode(self::scrub($decoded));
    }
}
