<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1785 — la provenance d'un article importe depuis un flux RSS.
 *
 * ## Pourquoi une migration, alors que le CDC demande « le minimum »
 *
 * Elle est indispensable, et voici ce qui a ete regarde avant de l'ecrire.
 *
 * `blog_posts` porte DEJA une colonne de provenance, `ai_origin` (JSON,
 * nullable). Elle a ete ecartee pour deux raisons :
 *
 *  1. **elle est specifique.** `LoopAnswerCapitalizationService:264` la
 *     documente comme « la provenance persistee sur l'Article » d'une reponse
 *     de Boucle capitalisee. Y ranger aussi des imports RSS melangerait deux
 *     provenances de natures differentes dans un champ dont le nom en annonce
 *     une seule ;
 *  2. **elle ne peut pas porter l'unicite.** Le CDC exige que le meme article
 *     source ne soit pas recree, et que cette unicite se calcule PAR
 *     ORGANIZATION. Sur une colonne JSON, cette garantie ne pourrait vivre que
 *     dans du code applicatif — donc elle tomberait au premier double clic ou
 *     a la premiere requete concurrente.
 *
 * ## Ce que l'index unique achete
 *
 * `(organization_id, source_guid)` rend l'anti-doublon ATOMIQUE, et par la
 * meme occasion l'import idempotent : deux clics simultanes ne peuvent pas
 * creer deux articles pour le meme GUID. C'est la base qui le garantit, pas
 * une lecture suivie d'une ecriture.
 *
 * L'unicite est bien PAR ORGANIZATION : le meme article source peut entrer
 * chez OFSH et chez LaunchPals, jamais deux fois chez OFSH.
 *
 * ## Non destructive, et sans effet sur l'existant
 *
 * Trois colonnes NULLABLES ajoutees, aucune touchee, aucune supprimee. Les
 * articles deja en base restent valides avec leurs trois `NULL`, et un `NULL`
 * n'entre pas en collision dans un index unique — ni sous PostgreSQL ni sous
 * SQLite. Les articles ecrits a la main ne sont donc jamais genes par cet
 * index, quel que soit leur nombre.
 *
 * `down()` retire l'index puis les colonnes : la provenance est perdue, mais
 * aucun article ne l'est.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blog_posts', function (Blueprint $table) {
            // Le GUID du flux : l'identite que WordPress promet stable, meme
            // si l'adresse publique change.
            $table->string('source_guid', 500)->nullable()->after('ai_origin');

            // L'URL canonique de l'article d'origine. Elle sert l'attribution
            // AFFICHEE — le CDC l'exige, « attribution et lien visible vers la
            // source » — et de second recours d'identite quand un flux sert un
            // GUID instable.
            $table->string('source_url', 2048)->nullable()->after('source_guid');

            // La date de publication a la SOURCE, conservee « comme information
            // de provenance » (CDC §3). Distincte de `published_at`, qui dit
            // quand BouclePro a publie : un article de 2019 importe aujourd'hui
            // ne doit pas se faire passer pour un article de 2019 chez nous.
            $table->timestamp('source_published_at')->nullable()->after('source_url');
        });

        Schema::table('blog_posts', function (Blueprint $table) {
            $table->unique(['organization_id', 'source_guid'], 'blog_posts_org_source_guid_unique');
        });
    }

    public function down(): void
    {
        Schema::table('blog_posts', function (Blueprint $table) {
            $table->dropUnique('blog_posts_org_source_guid_unique');
        });

        Schema::table('blog_posts', function (Blueprint $table) {
            $table->dropColumn(['source_guid', 'source_url', 'source_published_at']);
        });
    }
};
