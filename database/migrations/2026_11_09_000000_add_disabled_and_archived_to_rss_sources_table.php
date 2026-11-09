<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1792 — deux etats INDEPENDANTS pour une source RSS, decides par MASTER :
 * desactiver (bloque son actualisation) et archiver (la masque de la liste par
 * defaut). Aucun des deux n'efface quoi que ce soit — les `RssSourceArticle`
 * deja decouverts restent en base, intouches.
 *
 * Deux colonnes nullables, additives, non destructives — meme motif que
 * `blog_posts.source_guid`/`source_url`/`source_published_at` (TASK-1789) :
 * `NULL` = l'etat normal, une date posee = l'etat bascule. Pas de booleen,
 * pour la MEME raison qu'ailleurs dans ce depot (`banned_at`, `deleted_at`) :
 * la date dit AUSSI depuis quand, utile pour une recette ou un audit, et un
 * booleen ne le dirait jamais.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rss_sources', function (Blueprint $table) {
            $table->timestamp('disabled_at')->nullable()->after('last_fetched_at');
            $table->timestamp('archived_at')->nullable()->after('disabled_at');
        });
    }

    public function down(): void
    {
        Schema::table('rss_sources', function (Blueprint $table) {
            $table->dropColumn(['disabled_at', 'archived_at']);
        });
    }
};
