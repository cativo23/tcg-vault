<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Collection\Models\Collection;
use App\Modules\Collection\Scopes\TenantScope;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

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

        $username = config('tcgvault.admin_username') ?: Str::of(explode('@', $email)[0])
            ->lower()
            ->replaceMatches('/[^a-z0-9]/', '')
            ->toString();

        // Validate through the SAME rules the profile form and
        // registration use (User::usernameRules()) — found via a
        // background review that this path bypassed every one of them,
        // letting an unvalidated value (wrong case, illegal characters, a
        // reserved word) become a public gallery URL segment.
        // `$existingUser?->id` ignores this exact email's own row on a
        // re-seed, so re-running with the same TCGVAULT_ADMIN_USERNAME
        // doesn't reject itself as "taken".
        $existingUser = User::where('email', $email)->first();

        Validator::make(
            ['username' => $username],
            ['username' => User::usernameRules($existingUser?->id)],
        )->validate();

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Carlos',
                'username' => $username,
                'password' => bcrypt($password),
                'email_verified_at' => now(),
            ],
        );

        // Console context has no authenticated user, so TenantScope's
        // fail-closed default (see app/Modules/Collection/Scopes/TenantScope.php)
        // would filter this query to `where user_id is null` and never find
        // the row on a re-seed — hitting the unique [user_id, slug]
        // constraint on every run after the first. withoutGlobalScope() is
        // the explicit, auditable opt-out this exact situation exists for.
        Collection::withoutGlobalScope(TenantScope::class)->firstOrCreate(
            ['user_id' => $user->id, 'slug' => 'my-collection'],
            ['name' => 'My Collection', 'is_public' => true],
        );
    }
}
