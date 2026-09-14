<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('tcgvault.admin_email');
        $password = config('tcgvault.admin_password');

        if (! $email || ! $password) {
            throw new \RuntimeException(
                'TCGVAULT_ADMIN_EMAIL and TCGVAULT_ADMIN_PASSWORD must be set in .env before seeding — '
                . 'there is no default, to avoid ever seeding a guessable admin password.',
            );
        }

        User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Carlos',
                'password' => bcrypt($password),
                'email_verified_at' => now(),
            ],
        );
    }
}
