<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1240 : registre de provenance d'un scenario pack (contrat
 * TASK-1239), Organization-scoped strict.
 *
 * Une ligne = l'etat courant d'UN pack (`pack_id`) charge dans UNE
 * Organization : version reellement chargee, horodatage de chargement et de
 * dernier reset. Le couple (organization_id, pack_id) est unique : rejouer
 * le chargement du meme pack sur la meme Organization met a jour cette
 * ligne (idempotence), il n'y a jamais deux lignes actives concurrentes pour
 * le meme pack sur la meme Organization.
 *
 * Additif. Aucun historique multi-version n'est conserve ici : ce n'est pas
 * exige par le contrat TASK-1239 (qui ne demande que l'etat courant
 * verifiable), et l'ajouter maintenant serait une extension non demandee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scenario_pack_loads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('pack_id', 100);
            $table->string('pack_version', 20);
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->timestamp('loaded_at');
            $table->timestamp('reset_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'pack_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scenario_pack_loads');
    }
};
