<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1229 : `feature` sur le ledger canonique — la fonction produit qui a
 * emis l'invocation, avec la MEME semantique que `ai_interactions.feature`.
 * Additif, nullable : seuls les ecrivains qui portent une feature distincte
 * de leur capability la renseignent (aujourd'hui : les essais de doctrine,
 * `ai_doctrine_sandbox`, dont la recherche documentaire — une invocation
 * embedding reelle, correlee a l'essai — doit rester HORS credit utilisateur
 * tout en comptant dans le budget de l'Organization).
 *
 * Remplissage des lignes historiques : deterministe, par la correlation
 * partagee (TASK-1220 : un essai = une requete = un `correlation_id` pour sa
 * recherche ET sa generation) avec une generation `ai_doctrine_sandbox` de
 * `ai_interactions`. Aucune autre ligne n'est touchee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_provider_invocations', function (Blueprint $table) {
            $table->string('feature', 50)->nullable()->after('capability');
        });

        DB::table('ai_provider_invocations')
            ->whereNull('feature')
            ->whereNotNull('correlation_id')
            ->whereIn('correlation_id', static function ($query): void {
                $query->select('correlation_id')
                    ->from('ai_interactions')
                    ->where('feature', 'ai_doctrine_sandbox')
                    ->whereNotNull('correlation_id');
            })
            ->update(['feature' => 'ai_doctrine_sandbox']);
    }

    public function down(): void
    {
        Schema::table('ai_provider_invocations', function (Blueprint $table) {
            $table->dropColumn('feature');
        });
    }
};
