<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => config('tcgvault.admin_email')],
            [
                'name' => 'Carlos',
                'password' => bcrypt(config('tcgvault.admin_password')),
                'email_verified_at' => now(),
            ],
        );
    }
}
