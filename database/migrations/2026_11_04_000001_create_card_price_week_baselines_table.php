<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The 7-day move's comparison price per marketplace print (see
 * WeekBaselines): one row per (card, source, variant, currency) that has a
 * priced row 7 to 14 days before its newest priced day. A read model,
 * rebuilt from card_price_snapshots; nothing else writes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_price_week_baselines', function (Blueprint $table) {
            $table->foreignId('card_id')->constrained('cards')->cascadeOnDelete();
            $table->string('source');
            $table->string('variant');
            $table->char('currency', 3);
            $table->date('anchor_on');
            $table->unsignedBigInteger('baseline_id');
            $table->date('baseline_on');
            $table->string('baseline_origin', 16);
            $table->bigInteger('baseline_minor');

            $table->primary(['card_id', 'source', 'variant', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_price_week_baselines');
    }
};
