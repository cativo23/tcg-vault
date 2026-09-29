<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Mail systems treat an address's case as insignificant, so
     * `ash@x.com` and `Ash@x.com` are one inbox and must be one account.
     * Existing addresses are lowercased (every form already requires
     * lowercase; only an invite could store capitals) and a unique index
     * on lower(email) makes the database refuse a second spelling.
     *
     * Two accounts that already share an inbox are not merged or picked
     * between here: that decision belongs to a person, so the migration
     * stops and names the address instead.
     */
    public function up(): void
    {
        $duplicates = DB::table('users')
            ->selectRaw('lower(email) as address')
            ->groupByRaw('lower(email)')
            ->havingRaw('count(*) > 1')
            ->pluck('address');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'These addresses belong to more than one account and need a manual decision before this migration can run: '
                .$duplicates->implode(', ')
            );
        }

        DB::statement('UPDATE users SET email = lower(email), updated_at = now() WHERE email <> lower(email)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS users_email_lower_unique ON users (lower(email))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_email_lower_unique');
    }
};
