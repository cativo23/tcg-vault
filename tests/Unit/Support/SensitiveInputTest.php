<?php

use App\Support\SensitiveInput;

test('top-level password fields are masked', function () {
    $scrubbed = SensitiveInput::scrub([
        'email' => 'ash@example.com',
        'password' => 'hunter2',
        'password_confirmation' => 'hunter2',
        'current_password' => 'old-secret',
    ]);

    expect($scrubbed)->toBe([
        'email' => 'ash@example.com',
        'password' => SensitiveInput::MASK,
        'password_confirmation' => SensitiveInput::MASK,
        'current_password' => SensitiveInput::MASK,
    ]);
});

test('a password reset token is masked', function () {
    expect(SensitiveInput::scrub(['token' => 'abc123', 'email' => 'a@b.c']))
        ->toBe(['token' => SensitiveInput::MASK, 'email' => 'a@b.c']);
});

test('nested and dotted Livewire update keys are masked', function () {
    $scrubbed = SensitiveInput::scrub([
        'components' => [[
            'updates' => ['form.password' => 'hunter2', 'form.email' => 'ash@example.com'],
            'calls' => [],
        ]],
    ]);

    expect($scrubbed['components'][0]['updates'])->toBe([
        'form.password' => SensitiveInput::MASK,
        'form.email' => 'ash@example.com',
    ]);
});

test('passwords inside a Livewire snapshot JSON string are masked', function () {
    $snapshot = json_encode([
        'data' => ['form' => [['email' => 'ash@example.com', 'password' => 'hunter2'], ['s' => 'form']]],
        'memo' => ['name' => 'pages.auth.login'],
        'checksum' => 'abc',
    ]);

    $scrubbed = SensitiveInput::scrub(['components' => [['snapshot' => $snapshot]]]);
    $decoded = json_decode($scrubbed['components'][0]['snapshot'], true);

    expect($scrubbed['components'][0]['snapshot'])->not->toContain('hunter2')
        ->and($decoded['data']['form'][0])->toBe(['email' => 'ash@example.com', 'password' => SensitiveInput::MASK])
        ->and($decoded['memo'])->toBe(['name' => 'pages.auth.login']);
});

test('a snapshot that is not valid JSON is left as it is', function () {
    expect(SensitiveInput::scrub(['snapshot' => 'not json']))->toBe(['snapshot' => 'not json']);
});

test('an empty password is not reported as masked', function () {
    expect(SensitiveInput::scrub(['password' => '']))->toBe(['password' => '']);
});

test('ordinary fields that merely resemble a keyword are kept', function () {
    $input = ['notes' => 'my password is in the binder', 'tokens_used' => 3, 'name' => 'Ash'];

    expect(SensitiveInput::scrub($input))->toBe($input);
});
