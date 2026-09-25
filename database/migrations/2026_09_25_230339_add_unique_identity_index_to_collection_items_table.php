<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A plain composite unique index treats each NULL as distinct in
     * Postgres, so two rows that are both e.g. ungraded (grade_company/
     * grade_value both null) would NOT collide under a naive unique on
     * (collection_id, card_id, variant, condition, grade_company,
     * grade_value) — the COALESCE normalizes each nullable identity
     * column to a fixed sentinel so the constraint actually catches
     * every duplicate, nullable or not.
     *
     * This closes the check-then-insert race
     * CollectionService::addItemForCard() cannot on its own: two
     * concurrent adds of the same identity can both miss the existing
     * row in their own SELECT before either commits, but only one
     * INSERT can win against this index — addItemForCard() catches the
     * violation and merges into the winner, same shape as
     * InviteManager::createInvite() against invites_usable_email_unique.
     */
    public function up(): void
    {
        // Rows written before CollectionService normalized '' to null
        // (Livewire skips ConvertEmptyStringsToNull, so a cleared form
        // field could have been stored as '') would otherwise sit in the
        // same COALESCE bucket as a genuinely-null row without matching
        // it in application code's whereNull() checks — collapse them to
        // null here so the index and the app agree on what "unset" means.
        // Verified none exist in production as of 2026-09-25, but this
        // makes the migration correct regardless of when/where it runs.
        DB::table('collection_items')->where('variant', '')->update(['variant' => null]);
        DB::table('collection_items')->where('grade_company', '')->update(['grade_company' => null]);
        DB::table('collection_items')->where('grade_value', '')->update(['grade_value' => null]);

        DB::statement(
            'CREATE UNIQUE INDEX collection_items_identity_unique ON collection_items '
            .'(collection_id, card_id, COALESCE(variant, \'\'), condition, COALESCE(grade_company, \'\'), COALESCE(grade_value, \'\'))',
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS collection_items_identity_unique');
    }
};
