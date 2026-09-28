<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1653 — l'ancre EXACTE du monde materialise.
 *
 * ## La dette que cette colonne ferme
 *
 * T1652 a mesure que `loaded_at` n'est PAS l'instant qui a servi a
 * materialiser le monde. `ScenarioPackLoader` l'ecrit AVANT d'appeler
 * `apply()`, tandis que `ManifestScenarioPack::apply()` fabrique son propre
 * instant A L'INTERIEUR — apres 22 hachages bcrypt et la creation des Boucles.
 * L'ecart est de quelques centaines de millisecondes en test, de plusieurs
 * secondes en `BCRYPT_ROUNDS=12`.
 *
 * Or le Manifest declare des OFFSETS relatifs a une ancre. Capturer en
 * relisant `loaded_at` faisait donc glisser TOUS les offsets d'une minute des
 * que l'ecart depassait 30 secondes — uniformement, donc sans rendre le
 * document invalide : une derive silencieuse, et cumulative sur les
 * generations `capture -> load -> capture`.
 *
 * ## Pourquoi NULLABLE, et pourquoi aucun backfill
 *
 * Pour un chargement anterieur a cette colonne, l'ancre exacte n'a jamais ete
 * ecrite nulle part : elle est PERDUE. La deviner — en prenant `loaded_at`, ou
 * `created_at`, ou le premier `created_at` d'un objet du monde — reviendrait a
 * fabriquer la meme approximation que celle qu'on repare, mais en la faisant
 * passer pour une mesure.
 *
 * La valeur historique est donc `NULL`, et Capture REFUSE proprement en
 * disant quoi faire : un Reset reconstruit le monde avec une ancre connue, et
 * la Capture redevient possible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scenario_pack_loads', function (Blueprint $table) {
            // Juste apres `loaded_at`, dont elle est la version EXACTE : les
            // deux voisines disent la difference mieux qu'un commentaire.
            $table->timestamp('world_anchored_at')->nullable()->after('loaded_at');
        });
    }

    public function down(): void
    {
        Schema::table('scenario_pack_loads', function (Blueprint $table) {
            $table->dropColumn('world_anchored_at');
        });
    }
};
