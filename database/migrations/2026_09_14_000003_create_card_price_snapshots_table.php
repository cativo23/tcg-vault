<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_price_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained('cards')->cascadeOnDelete();
            $table->string('source'); // 'cardmarket' | 'tcgplayer'
            $table->string('variant'); // e.g. 'default', 'normal', 'holofoil', 'reverse-holofoil'
            $table->date('captured_on');
            $table->char('currency', 3);
            $table->bigInteger('market_minor')->nullable();
            $table->bigInteger('low_minor')->nullable();
            $table->bigInteger('trend_minor')->nullable();
            $table->jsonb('raw')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['card_id', 'source', 'variant', 'captured_on'], 'card_price_snapshots_unique_capture');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_price_snapshots');
    }
};
