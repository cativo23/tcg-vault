<?php

declare(strict_types=1);

use App\Modules\Settings\Models\Setting;

test('get falls back to the given default when no row exists', function () {
    expect(Setting::get('registration.open', false))->toBeFalse();
});

test('set persists a value that get then returns', function () {
    Setting::set('registration.open', true);

    expect(Setting::get('registration.open', false))->toBeTrue();
});

test('set overwrites an existing value rather than duplicating the row', function () {
    Setting::set('registration.open', true);
    Setting::set('registration.open', false);

    expect(Setting::get('registration.open', true))->toBeFalse();
    expect(Setting::query()->where('key', 'registration.open')->count())->toBe(1);
});

test('get reflects a value changed since the last get, even through the cache', function () {
    Setting::set('registration.open', false);
    expect(Setting::get('registration.open'))->toBeFalse();

    Setting::set('registration.open', true);
    expect(Setting::get('registration.open'))->toBeTrue();
});

test('set stores non-boolean JSON-serializable values', function () {
    Setting::set('some.list', ['a', 'b', 'c']);

    expect(Setting::get('some.list'))->toBe(['a', 'b', 'c']);
});

test('a stale cached value self-heals within a bounded TTL instead of staying wrong forever', function () {
    Setting::set('registration.open', true);
    expect(Setting::get('registration.open'))->toBeTrue();

    // Simulate the cache/DB drift a concurrent set() race could leave
    // behind — the DB changes without going through set()'s own cache
    // write, standing in for "the cache still holds the pre-race value".
    // Through a model instance, not the query builder, so the `value`
    // json cast actually applies on write.
    Setting::where('key', 'registration.open')->first()->update(['value' => false]);

    $this->travel(10)->minutes();

    expect(Setting::get('registration.open'))->toBeFalse();
});
