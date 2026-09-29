<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Mail systems treat an address's case as insignificant, so two
     * pending invites for `a@x.com` and `A@x.com` are two live links to
     * one inbox. Keying the index on lower(email) makes the database
     * refuse that the same way it refuses an exact duplicate. Existing
     * rows keep their stored spelling: an emailed link's hash is built
     * from it, so rewriting it would break links already sent.
     *
     * Addresses that already hold more than one pending invite under
     * different spellings would make the index impossible to build, so
     * all but one are revoked first. The one kept is still usable if any
     * is, and otherwise the newest.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE invites SET revoked_at = now(), updated_at = now()
            WHERE id IN (
                SELECT id FROM (
                    SELECT id, row_number() OVER (
                        PARTITION BY lower(email)
                        ORDER BY (expires_at > now()) DESC, created_at DESC, id DESC
                    ) AS position
                    FROM invites
                    WHERE used_at IS NULL AND revoked_at IS NULL
                ) ranked
                WHERE position > 1
            )
            SQL);

        DB::statement('DROP INDEX IF EXISTS invites_usable_email_unique');
        DB::statement(
            'CREATE UNIQUE INDEX invites_usable_email_unique ON invites (lower(email)) '
            .'WHERE used_at IS NULL AND revoked_at IS NULL',
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS invites_usable_email_unique');
        DB::statement(
            'CREATE UNIQUE INDEX invites_usable_email_unique ON invites (email) '
            .'WHERE used_at IS NULL AND revoked_at IS NULL',
        );
    }
};
