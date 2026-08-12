<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1131 / IA P1-1 — observabilité des interactions IA.
 *
 * Colonnes additives et nullable : les lignes historiques restent valides.
 * Aucun backfill : on ne fabrique pas de fausses corrélations rétroactives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_interactions', function (Blueprint $table) {
            $table->uuid('correlation_id')->nullable();
            $table->string('process', 100)->nullable();

            $table->index('correlation_id');
            $table->index('process');
        });
    }

    public function down(): void
    {
        Schema::table('ai_interactions', function (Blueprint $table) {
            $table->dropIndex(['correlation_id']);
            $table->dropIndex(['process']);
            $table->dropColumn(['correlation_id', 'process']);
        });
    }
};
