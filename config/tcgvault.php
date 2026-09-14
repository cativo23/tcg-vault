<?php

declare(strict_types=1);

return [
    // No PHP-level fallback for these two: a silent default ("password") is
    // exactly the weak-default-credentials risk that must never ship. Both
    // must come from the environment; the seeder fails loudly if they're
    // missing rather than seeding a guessable admin account.
    'admin_email' => env('TCGVAULT_ADMIN_EMAIL'),
    'admin_password' => env('TCGVAULT_ADMIN_PASSWORD'),

    // This is a single-admin personal vault, not a multi-tenant SaaS — an
    // open /register is unwanted account-creation surface. Off by default;
    // flip it on only for the rare case a second account is genuinely
    // wanted.
    'allow_registration' => env('TCGVAULT_ALLOW_REGISTRATION', false),
];
