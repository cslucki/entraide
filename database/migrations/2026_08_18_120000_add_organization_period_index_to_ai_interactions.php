<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1228 : index additif pour les agregations economiques par Organization
 * et par periode (`AiEconomicGuard`, console 1219, autorite 1222, releves
 * 1228) : `ai_interactions (organization_id, created_at)`. `foreignUuid()`
 * n'indexe pas la colonne sur PostgreSQL ; seul `created_at` l'etait.
 * Aucune donnee modifiee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_interactions', function (Blueprint $table) {
            $table->index(['organization_id', 'created_at'], 'ai_interactions_organization_period_index');
        });
    }

    public function down(): void
    {
        Schema::table('ai_interactions', function (Blueprint $table) {
            $table->dropIndex('ai_interactions_organization_period_index');
        });
    }
};
