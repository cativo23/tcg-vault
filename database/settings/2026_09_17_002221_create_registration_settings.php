<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Seeded from the env-configured default at migration time —
        // after this runs, the settings row is always the real source
        // of truth; no runtime config() fallback needed on the read
        // side (unlike the hand-rolled store this replaces).
        $this->migrator->add('registration.open', config('tcgvault.allow_registration'));
    }
};
