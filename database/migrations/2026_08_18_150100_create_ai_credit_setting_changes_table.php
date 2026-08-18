<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1229 : trace des changements de reglage du credit IA par utilisateur
 * (auteur, horodatage, avant/apres), au niveau plateforme comme au niveau
 * Organization. Un changement de quota est un acte d'administration : il
 * est trace comme la doctrine (TASK-1227), jamais silencieux.
 *
 * Additif. `organization_id` NULL = reglage plateforme.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_credit_setting_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('scope', 20)->index();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->json('changes');
            $table->foreignUuid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_credit_setting_changes');
    }
};
