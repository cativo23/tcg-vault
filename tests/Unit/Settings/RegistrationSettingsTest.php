<?php

declare(strict_types=1);

use App\Settings\RegistrationSettings;

test('the registration_settings migration seeds a real row, not a runtime fallback', function () {
    // No RegistrationSettings::save() call anywhere in this test —
    // proves the row already exists from the settings migration,
    // unlike the hand-rolled store this replaced (which needed a
    // fallback default on every read until something wrote a row).
    expect(app(RegistrationSettings::class)->open)->toBeBool();
});

test('save persists a value that a fresh resolution then returns', function () {
    $settings = app(RegistrationSettings::class);
    $settings->open = ! $settings->open;
    $newValue = $settings->open;
    $settings->save();

    expect(app(RegistrationSettings::class)->open)->toBe($newValue);
});
