<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1132 / IA P1-2 — cout inconnu explicite sur `admin_ai_interactions`.
 *
 * Meme representation que sur `ai_interactions` : une seule semantique pour les
 * trois tables de trace, pas trois variantes. Voir la migration
 * `2026_08_12_090000_add_cost_unknown_to_ai_interactions_table` pour le detail
 * de la semantique de `cost_unknown` et l'absence de backfill.
 *
 * La precision locale decimal(14,8) est conservee : P1-2 ne tente pas de
 * reconcilier la divergence de precision entre les deux tables.
 *
 * `App\Console\Commands\CheckAiBudgets` agrege `cost_usd` sur cette table.
 * SUM() ignore les NULL, donc le total reste arithmetiquement identique : les
 * appels sans tarif connu contribuaient 0, ils contribuent maintenant NULL.
 * Ce que la commande gagne, c'est de pouvoir dire combien d'appels echappent a
 * la mesure au lieu de les compter comme gratuits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_ai_interactions', function (Blueprint $table) {
            $table->decimal('cost_usd', 14, 8)->nullable()->change();
            $table->boolean('cost_unknown')->nullable();

            $table->index('cost_unknown');
        });
    }

    /**
     * Rollback destructif par nature : voir la migration `ai_interactions`.
     * Les couts non mesurables redeviennent des 0 indiscernables d'un gratuit.
     */
    public function down(): void
    {
        Schema::table('admin_ai_interactions', function (Blueprint $table) {
            $table->dropIndex(['cost_unknown']);
            $table->dropColumn('cost_unknown');
        });

        DB::table('admin_ai_interactions')->whereNull('cost_usd')->update(['cost_usd' => 0]);

        Schema::table('admin_ai_interactions', function (Blueprint $table) {
            $table->decimal('cost_usd', 14, 8)->default(0)->nullable(false)->change();
        });
    }
};
