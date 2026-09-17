<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A regular unique index on `email` would reject a legitimate
     * second invite once the first is used or revoked — the partial
     * index only enforces uniqueness among rows nobody has acted on
     * yet. This closes the check-then-insert race the app-level
     * duplicate check in InviteManager::createInvite() cannot: two
     * concurrent creates for the same email can both pass that check
     * before either commits, but only one INSERT can win against this
     * index.
     *
     * Deliberately does NOT also exclude expired-but-not-revoked
     * invites: Postgres requires a partial index's predicate to be
     * IMMUTABLE, and `expires_at > now()` is not (`now()` is only
     * STABLE) — Postgres rejects that predicate outright. This index
     * can therefore still be violated by a merely-expired row.
     * InviteManager::createInvite() handles that: on a violation, it
     * checks whether the conflicting row is actually usable or just
     * expired-and-forgotten, and if the latter, revokes it and retries
     * — so an expired invite self-heals on the next invite attempt
     * rather than permanently locking the email out until someone
     * remembers to revoke it by hand.
     */
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX invites_usable_email_unique ON invites (email) '
            .'WHERE used_at IS NULL AND revoked_at IS NULL',
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS invites_usable_email_unique');
    }
};
