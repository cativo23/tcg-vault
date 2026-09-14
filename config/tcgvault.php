<?php

declare(strict_types=1);

return [
    // No PHP-level fallback for these two: a silent default ("password") is
    // exactly the weak-default-credentials risk that must never ship. Both
    // must come from the environment; the seeder fails loudly if they're
    // missing rather than seeding a guessable admin account.
    'admin_email' => env('TCGVAULT_ADMIN_EMAIL'),
    'admin_password' => env('TCGVAULT_ADMIN_PASSWORD'),

    // Optional. The seeder derives a username from admin_email's local
    // part when this is unset — fine as a default, but the derived value
    // necessarily echoes a fragment of the real email, and that username
    // now appears in public gallery URLs (Phase 3). Set this to pick a
    // public handle deliberately instead (e.g. an existing public
    // username used elsewhere) rather than relying on the derived one.
    'admin_username' => env('TCGVAULT_ADMIN_USERNAME'),

    // This is a single-admin personal vault, not a multi-tenant SaaS — an
    // open /register is unwanted account-creation surface. Off by default;
    // flip it on only for the rare case a second account is genuinely
    // wanted.
    'allow_registration' => env('TCGVAULT_ALLOW_REGISTRATION', false),
];
