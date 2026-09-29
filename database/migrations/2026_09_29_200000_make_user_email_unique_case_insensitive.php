<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Mail systems treat an address's case as insignificant, so
     * `ash@x.com` and `Ash@x.com` are one inbox and must be one account.
     * Existing addresses are lowercased (every form already requires
     * lowercase; an invite or the admin seeder could store capitals) and a
     * unique index on lower(email) makes the database refuse a second
     * spelling.
     *
     * Two accounts that already share an inbox are not merged or picked
     * between here: that decision belongs to a person, so the migration
     * stops and lists the account ids. Only ids: the message can end up in
     * logs and error reports.
     *
     * Pending reset links stored under a capitalised address stop matching
     * once it is lowercased, so they are dropped; the member can ask for a
     * new one. Rolling back drops the index but keeps the lowercased
     * addresses.
     */
    public function up(): void
    {
        $duplicates = DB::table('users')
            ->selectRaw("string_agg(id::text, '+' ORDER BY id) as ids")
            ->groupByRaw('lower(email)')
            ->havingRaw('count(*) > 1')
            ->pluck('ids');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'These user ids share one email address and need a manual decision before this migration can run: '
                .$duplicates->implode(', ')
            );
        }

        DB::statement('UPDATE users SET email = lower(email), updated_at = now() WHERE email <> lower(email)');
        // Dropped rather than moved: the address is the table's primary key,
        // so moving one next to its lowercase twin would fail, and a reset
        // link expires within the hour anyway.
        DB::statement('DELETE FROM password_reset_tokens WHERE email <> lower(email)');
        DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (lower(email))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_email_lower_unique');
    }
};
