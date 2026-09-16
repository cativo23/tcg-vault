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
