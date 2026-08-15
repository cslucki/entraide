<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1212 (IA P4-lite) : configuration IA par Organization.
 *
 * Provider, modele, credential (chiffre au repos) et budget mensuel sont
 * portes par le tenant. Une Organization sans ligne, desactivee ou sans
 * credential n'a PAS d'IA transverse : aucun repli vers la cle plateforme.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_ai_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->unique()->constrained('organizations')->cascadeOnDelete();
            $table->string('provider', 50);
            $table->string('model', 150);
            // Chiffre par le cast `encrypted` du modele : jamais en clair en base.
            $table->text('api_key')->nullable();
            $table->decimal('monthly_budget_usd', 10, 2)->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('api_key_updated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_ai_settings');
    }
};
