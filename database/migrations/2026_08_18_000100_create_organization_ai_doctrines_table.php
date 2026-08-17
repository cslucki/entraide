<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1227 : doctrine IA de l'Organization, versionnee.
 *
 * Une ligne = une version. Une seule version `active` par Organization
 * (garantie par la primitive d'ecriture `OrganizationAiDoctrine::activate()`,
 * en transaction) ; les precedentes passent `superseded` et sont conservees
 * (historique, auteur, horodatage). Additive : rien n'est modifie ailleurs.
 *
 * `organization_ai_settings` reste la configuration TECHNIQUE (provider,
 * credential, budget) ; la doctrine est du TEXTE editorial : deux tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_ai_doctrines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('body');
            $table->string('status', 20)->index();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'version']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_ai_doctrines');
    }
};
