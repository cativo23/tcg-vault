<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The TCGplayer groups to pull prices from for a set. A set can span
 * several (30th Celebration and its Classic Collection are two groups).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('set_tcgplayer_groups', function (Blueprint $table) {
            $table->foreignId('set_id')->constrained('sets')->cascadeOnDelete();
            $table->unsignedInteger('group_id');

            $table->primary(['set_id', 'group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('set_tcgplayer_groups');
    }
};
