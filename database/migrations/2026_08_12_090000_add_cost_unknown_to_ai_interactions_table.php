<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1132 / IA P1-2 — cout inconnu explicite sur `ai_interactions`.
 *
 * Deux changements, tous deux additifs et compatibles :
 *
 * 1. `cost_usd` devient nullable et perd son DEFAULT 0. Un cout non mesurable
 *    doit pouvoir rester NULL : ecrire 0 en fabriquerait la mesure.
 *    La precision decimal(12,6) est conservee telle quelle. La divergence avec
 *    `admin_ai_interactions` (14,8) est connue et hors perimetre de P1-2.
 *
 * 2. `cost_unknown` est ajoutee en boolean NULLABLE, sans backfill.
 *
 * Semantique de `cost_unknown` :
 *   null  -> statut de cout non evalue (lignes anterieures a P1-2) ;
 *   false -> cout connu, y compris un 0 legitime (tarif reellement nul) ;
 *   true  -> cout non mesurable, `cost_usd` vaut alors NULL.
 *
 * AUCUN BACKFILL : un ancien `cost_usd = 0` n'est pas reinterprete comme une
 * mesure certaine, ni requalifie en inconnu. Les lignes historiques gardent
 * leur valeur et recoivent `cost_unknown = null`, qui dit exactement ce qui est
 * vrai : ce statut n'a jamais ete evalue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_interactions', function (Blueprint $table) {
            $table->decimal('cost_usd', 12, 6)->nullable()->change();
            $table->boolean('cost_unknown')->nullable();

            $table->index('cost_unknown');
        });
    }

    /**
     * Rollback destructif par nature : revenir a NOT NULL DEFAULT 0 force les
     * couts non mesurables a valoir 0, c'est-a-dire a redevenir indiscernables
     * d'un modele gratuit. La distinction est perdue, pas reconstituable.
     */
    public function down(): void
    {
        Schema::table('ai_interactions', function (Blueprint $table) {
            $table->dropIndex(['cost_unknown']);
            $table->dropColumn('cost_unknown');
        });

        // Sans cela, la contrainte NOT NULL echouerait sur les lignes dont le
        // cout est reste NULL parce qu'il n'etait pas mesurable.
        DB::table('ai_interactions')->whereNull('cost_usd')->update(['cost_usd' => 0]);

        Schema::table('ai_interactions', function (Blueprint $table) {
            $table->decimal('cost_usd', 12, 6)->default(0)->nullable(false)->change();
        });
    }
};
