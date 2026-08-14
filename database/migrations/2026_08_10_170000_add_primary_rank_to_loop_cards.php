<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les outils **mis en avant** d'une Boucle precise (TASK-1124).
 *
 * Une seule colonne, nullable, additive — aucun backfill.
 *
 *   NULL   la Card active n'est pas mise en avant dans cette Boucle
 *   0..2   elle l'est, et l'entier donne l'ordre
 *
 * `enabled` reste l'unique verite sur le fait qu'un outil est **actif** :
 * mettre en avant ou retirer des principaux ne le touche jamais.
 *
 * **La regle se lit au niveau de la Boucle, pas de la ligne.** Une Boucle
 * sans aucun rang explicite est en mode *derive* : ses principaux sont ses
 * premieres Cards actives dans l'ordre du catalogue — le comportement
 * d'avant, rendu explicite, donc zero rupture pour l'existant. Des qu'un rang
 * est pose, la Boucle bascule en mode *explicite* et `NULL` y signifie
 * « secondaire ». Lire `NULL = secondaire` ligne a ligne aurait fait perdre
 * leurs trois outils principaux a toutes les Boucles historiques.
 *
 * Aucune contrainte de base sur « maximum 3 » : la coherence est portee par
 * le service canonique (LoopCardCompositionService) et testee. Une contrainte
 * d'unicite partielle sur (loop_id, primary_rank) serait naturelle sur
 * PostgreSQL, mais SQLite ne sait pas l'ajouter a une table existante — la
 * poser ici ferait diverger les deux moteurs sur un invariant deja tenu au
 * service. Elle n'apporterait rien que le service ne garantisse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loop_cards', function (Blueprint $table) {
            $table->smallInteger('primary_rank')->nullable()->after('enabled');
        });
    }

    public function down(): void
    {
        Schema::table('loop_cards', function (Blueprint $table) {
            $table->dropColumn('primary_rank');
        });
    }
};
