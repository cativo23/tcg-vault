<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Without an ON DELETE rule, any user referenced from `invites` could
     * not be deleted — which covers everyone who registered through an
     * invite, plus every staff member who ever created or revoked one.
     *
     * The invite a user accepted is deleted along with them: it carries
     * their email, and account deletion promises to remove their data.
     * Invites a staff member created or revoked belong to other people,
     * so those survive with the reference nulled out.
     */
    public function up(): void
    {
        Schema::table('invites', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropForeign(['accepted_by']);
            $table->dropForeign(['revoked_by']);
        });

        Schema::table('invites', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by')->nullable()->change();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('accepted_by')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('revoked_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Restoring NOT NULL on created_by fails if a creator has been deleted
     * since up() ran; those rows need a creator reassigned first.
     */
    public function down(): void
    {
        Schema::table('invites', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropForeign(['accepted_by']);
            $table->dropForeign(['revoked_by']);
        });

        Schema::table('invites', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by')->nullable(false)->change();

            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('accepted_by')->references('id')->on('users');
            $table->foreign('revoked_by')->references('id')->on('users');
        });
    }
};
