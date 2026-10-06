<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which TCGplayer product (and price subtype) a print is, stored once.
 * tcgdex doesn't always serve the product ids it holds, so prices fetched
 * from tcgcsv are matched through this table rather than re-read daily.
 * One product can feed two prints through different subtypes (Normal and
 * Reverse Holofoil), so product_id alone is not unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_tcgplayer_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained('cards')->cascadeOnDelete();
            $table->string('variant', 120); // a CardVariants key
            $table->unsignedInteger('product_id');
            $table->string('sub_type', 32); // 'Normal' | 'Holofoil' | 'Reverse Holofoil'
            $table->unsignedInteger('group_id')->nullable();
            $table->string('method', 24); // 'tcgdex-price' | 'tcgdex-thirdparty' | 'admin'
            $table->timestampTz('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['card_id', 'variant']);
            $table->index(['product_id', 'sub_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_tcgplayer_links');
    }
};
