<?php

declare(strict_types=1);

return [
    // No PHP-level fallback for these two: a silent default ("password") is
    // exactly the weak-default-credentials risk that must never ship. Both
    // must come from the environment; the seeder fails loudly if they're
    // missing rather than seeding a guessable admin account.
    'admin_email' => env('TCGVAULT_ADMIN_EMAIL'),
    'admin_password' => env('TCGVAULT_ADMIN_PASSWORD'),
];
