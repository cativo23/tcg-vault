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

    // Only the INITIAL value, seeded once into RegistrationSettings by
    // database/settings/..._create_registration_settings.php — after
    // that migration runs, App\Settings\RegistrationSettings::$open
    // (toggled from /staff/settings) is the real, live source of truth,
    // not this env var. Off by default: the beta is invite-only, and
    // opening public registration is a deliberate admin action, not a
    // deploy-time one.
    'allow_registration' => env('TCGVAULT_ALLOW_REGISTRATION', false),

    /**
     * Hand-maintained map of TCGplayer's own set codes (as they appear in
     * its Android app's collection/decklist export, e.g. "[PBL]") to the
     * matching tcgdex set id. tcgdex has no TCGplayer-code field on its Set
     * object, so this cannot be derived automatically — add an entry here
     * whenever a new set is exported and the code isn't recognized yet.
     * Each entry verified against real tcgdex data (card-count match, then
     * a specific card's real name cross-checked to rule out a same-count
     * collision — e.g. CRI/JTG/PRE all had 2-3 same-count candidates).
     * Verified 2026-09-15: PBL -> me05 ("Pitch Black"), MEE -> mee ("Mega
     * Evolution Energy", basic energy reprints). Verified 2026-09-15
     * (second batch): POR -> me03 ("Perfect Order"), CRI -> me04 ("Chaos
     * Rising"), ASC -> me02.5 ("Ascended Heroes"), PFL -> me02 ("Phantasmal
     * Flames"), MEG -> me01 ("Mega Evolution"), DRI -> sv10 ("Destined
     * Rivals"), JTG -> sv09 ("Journey Together"), PRE -> sv08.5
     * ("Prismatic Evolutions"), SSP -> sv08 ("Surging Sparks"), SWSH12 ->
     * swsh12 ("Silver Tempest").
     */
    'tcgplayer_set_map' => [
        'PBL' => 'me05',
        'MEE' => 'mee',
        'POR' => 'me03',
        'CRI' => 'me04',
        'ASC' => 'me02.5',
        'PFL' => 'me02',
        'MEG' => 'me01',
        'DRI' => 'sv10',
        'JTG' => 'sv09',
        'PRE' => 'sv08.5',
        'SSP' => 'sv08',
        'SWSH12' => 'swsh12',
    ],

    /**
     * The public example collection the landing page links to, seeded by
     * `php artisan demo:seed-gallery`. A dedicated account, never a real
     * member's, holding one near-mint raw copy of each chase card below,
     * so the prices it shows are honest raw market prices. Every card
     * joins the Catalog, which puts it on the daily price refresh.
     * The email uses the reserved .invalid TLD so nothing is ever sent.
     */
    'demo' => [
        'username' => env('TCGVAULT_DEMO_USERNAME', 'demo'),
        'email' => 'demo@tcg-vault.invalid',
        'cards' => [
            'swsh7-215',   // Umbreon VMAX (alt art), Evolving Skies
            'sv08.5-161',  // Umbreon ex (SIR), Prismatic Evolutions
            'swsh7-218',   // Rayquaza VMAX (alt art), Evolving Skies
            'swsh11-186',  // Giratina V (alt art), Lost Origin
            'me02-125',    // Mega Charizard X ex (SIR), Phantasmal Flames
            'swsh12-186',  // Lugia V (alt art), Silver Tempest
            'sv03.5-199',  // Charizard ex (SIR), 151
            'swsh8-270',   // Espeon VMAX (alt art), Fusion Strike
            'sv08-238',    // Pikachu ex (SIR), Surging Sparks
            'sv04.5-234',  // Charizard ex (SIR), Paldean Fates
            'me01-188',    // Mega Lucario ex (MHR), Mega Evolution
            'me05-116',    // Mega Darkrai ex (SIR), Pitch Black
        ],
    ],
];
