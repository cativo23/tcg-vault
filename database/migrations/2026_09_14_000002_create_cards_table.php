<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cards', function (Blueprint $table) {
            $table->id();
            $table->string('tcgdex_id')->unique();
            $table->foreignId('set_id')->constrained('sets')->cascadeOnDelete();
            $table->string('local_id'); // zero-padded as tcgdex returns it, e.g. "007"
            $table->string('name');
            $table->string('rarity')->nullable();
            $table->jsonb('variants')->nullable();
            $table->string('official_image_url')->nullable();
            $table->jsonb('raw')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['set_id', 'local_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cards');
    }
};
