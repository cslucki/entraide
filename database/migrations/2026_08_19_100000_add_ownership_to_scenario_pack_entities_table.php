<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1245 : `scenario_pack_entities` trace la PARTICIPATION d'une entite
 * a un scenario pack ; `ownership` dit si ce chargement a le DROIT DE LA
 * DETRUIRE. La presence dans le registre ne prouvait pas, a elle seule, que
 * l'entite avait ete creee par le pack : `ArtSciLabScenarioSeeder` declare
 * au registrar ce qu'il cree comme ce qu'il retrouve (`updateOrCreate` sur
 * cle naturelle), et un `remove()` purgeait donc sans distinction.
 *
 * Valeurs (voir `ScenarioPackEntity::OWNERSHIP_*`) :
 *  - `created` : physiquement creee par ce chargement -> le remover et le
 *    resetter peuvent la supprimer physiquement (`forceDelete` borne) ;
 *  - `reused`  : preexistante, referencee par le pack -> jamais supprimee,
 *    jamais modifiee ;
 *  - NULL      : ownership inconnu (ligne inscrite avant cette migration).
 *    AUCUN backfill : deviner `created` sur un chargement anterieur
 *    reviendrait a s'octroyer un droit de destruction non prouve. Le
 *    remover REFUSE explicitement de purger un chargement qui contient une
 *    telle ligne ; le nettoyage d'un banc deja charge est une operation
 *    manuelle controlee, hors code produit (decision MASTER 1, T1245).
 *
 * `ownership` est fixe a la premiere inscription d'une (`entity_type`,
 * `internal_key`) dans un `ScenarioPackLoad` et n'est plus jamais modifie
 * ensuite (un `reset()` ne transforme jamais `created` en `reused`).
 *
 * Additif, nullable, sans index (table de registre de petite taille).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scenario_pack_entities', function (Blueprint $table) {
            $table->string('ownership', 20)->nullable()->after('sequence');
        });
    }

    public function down(): void
    {
        Schema::table('scenario_pack_entities', function (Blueprint $table) {
            $table->dropColumn('ownership');
        });
    }
};
