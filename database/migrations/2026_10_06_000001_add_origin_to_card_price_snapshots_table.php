<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where a price came from, separate from `source` (the marketplace it
 * prices). TCGplayer prices can arrive through tcgdex or straight from
 * tcgcsv; keeping both under source='tcgplayer' keeps one series per
 * marketplace and print, so history, deltas and sparklines don't split.
 * Not part of the unique index for the same reason.
 *
 * Additive: a constant default adds the column without rewriting the
 * table, and code that doesn't know about it keeps working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('card_price_snapshots', function (Blueprint $table) {
            $table->string('origin', 16)->default('tcgdex')->after('source'); // 'tcgdex' | 'tcgcsv' | 'hand'
        });

        $this->backfillHandOrigin();
    }

    /** Manual prices are typed in by a person, not synced from anywhere. */
    public function backfillHandOrigin(): void
    {
        DB::table('card_price_snapshots')->where('source', 'manual')->update(['origin' => 'hand']);
    }

    public function down(): void
    {
        Schema::table('card_price_snapshots', function (Blueprint $table) {
            $table->dropColumn('origin');
        });
    }
};
