<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;

test('the resend mailer production uses can actually be built', function () {
    config([
        'mail.mailers.resend' => ['transport' => 'resend'],
        'services.resend.key' => 're_test_not_a_real_key',
    ]);

    expect(Mail::mailer('resend')->getSymfonyTransport())
        ->toBeInstanceOf(Illuminate\Mail\Transport\ResendTransport::class);
});
