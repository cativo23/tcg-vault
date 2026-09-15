<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collection_items', function (Blueprint $table) {
            // Set true by the TCGplayer bulk importer when tcgdex reports
            // more than one known price variant for a card but the export
            // text carries no signal for which physical copy is which
            // (TCGplayer's own format never marks holo/reverse-holo on the
            // line — found live 2026-09-15). Cleared the moment a real
            // variant is assigned via the edit modal.
            $table->boolean('needs_variant_review')->default(false)->after('variant');
        });
    }

    public function down(): void
    {
        Schema::table('collection_items', function (Blueprint $table) {
            $table->dropColumn('needs_variant_review');
        });
    }
};
