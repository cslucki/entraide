<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1240 : une ligne = UNE entite reelle creee par UN chargement de
 * scenario pack (`scenario_pack_loads`). C'est ce registre qui rend le
 * moteur capable de :
 *
 *  - IDEMPOTENCE (contrat TASK-1239 S10) : `internal_key` est la cle stable
 *    interne au pack (persona_id/loop_id/folder_id/interaction_id) ; rejouer
 *    un chargement retrouve la ligne existante via
 *    (scenario_pack_load_id, entity_type, internal_key) au lieu de recreer ;
 *  - SUPPRESSION BORNEE (S12) : seules les entites listees ici, pour ce
 *    chargement, sont retirees — jamais une suppression globale ;
 *  - RESET (S11) : les entites d'un chargement precedent qui ne sont plus
 *    produites par le pack courant sont detectables (absentes du nouveau
 *    passage) et retirees comme orphelines ;
 *  - PREUVES (S13/S14) : `organization_id` est duplique ici (pas seulement
 *    lisible via le chargement parent) pour permettre une verification
 *    directe et positive d'absence de fuite cross-tenant sur cette seule
 *    table, sans jointure.
 *
 * `sequence` est assignee par l'application (pas un auto-increment DB, pour
 * rester portable SQLite/PostgreSQL) : ordre d'enregistrement au sein d'un
 * chargement. Le moteur supprime dans l'ordre inverse de cette sequence,
 * pour ne jamais tenter de retirer un parent avant les enfants qui le
 * referencent encore.
 *
 * Additif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scenario_pack_entities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('scenario_pack_load_id')->constrained('scenario_pack_loads')->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('entity_type', 60);
            $table->string('internal_key', 150);
            $table->string('entity_model', 150);
            $table->uuid('entity_id');
            $table->unsignedInteger('sequence');
            $table->timestamps();

            $table->unique(['scenario_pack_load_id', 'entity_type', 'internal_key'], 'scenario_pack_entities_load_type_key_unique');
            $table->index(['organization_id', 'entity_model', 'entity_id']);
            $table->index(['scenario_pack_load_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scenario_pack_entities');
    }
};
