<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1789 — le cache durable des articles découverts dans un flux RSS.
 *
 * ## Remplace la session, pas `blog_posts`
 *
 * Jusqu'ici (`TASK-1785`), les articles lus dans un flux ne vivaient qu'en session —
 * perdus à la déconnexion. Cette table est le brouillon de lecture durable : elle ne
 * devient un `BlogPost` qu'au moment choisi par un SuperAdmin, exactement comme avant.
 * Aucun lien de clé étrangère vers `blog_posts` : la correspondance se fait par la
 * valeur de `source_identity`, qui est EXACTEMENT celle que produit déjà
 * `RssFeedReader::identite()` et qui est écrite dans `blog_posts.source_guid` à
 * l'import — même fabrique, deux emplacements de lecture.
 *
 * ## `unique(rss_source_id, source_identity)`, pas d'unicité globale
 *
 * Deux flux distincts peuvent en théorie produire la même empreinte (cas rarissime de
 * l'étage `titre:` de secours) sans que ce soit un doublon réel. L'unicité est donc
 * bornée à l'intérieur d'une même source, comme l'identité RSS le prévoit.
 *
 * ## Pas de suppression au rafraîchissement
 *
 * Décision MASTER explicite : un article qui disparaît du flux relu (WordPress ne sert
 * que ses N derniers items) reste en cache. Rien dans le code applicatif ne doit
 * supprimer une ligne de cette table sur un simple rechargement — voir
 * `AdminRssImportController::charger()`, qui UPSERT sans jamais `DELETE`.
 *
 * ## `guid`/`url`/`titre`/`auteur` en `text()` (revue MASTER, commit `f4953aea`)
 *
 * Ce sont des métadonnées EXTERNES, écrites par un flux WordPress qu'on ne contrôle
 * pas — un plugin peut servir un titre de plusieurs centaines de caractères, une URL
 * avec une querystring longue, un nom d'auteur compose. Aucune de ces quatre colonnes
 * n'est indexée ni jointe : seule `source_identity` (inchangée, `string(500)`, portant
 * l'unique) doit rester bornée pour que son index tienne. `text()` retire la limite
 * arbitraire sans rien coûter côté requête.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rss_source_articles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('rss_source_id')->constrained('rss_sources')->cascadeOnDelete();

            // Même valeur, même fabrique que `blog_posts.source_guid` — voir
            // `RssFeedReader::identite()`. Le prefixe (`guid:`/`url:`/`titre:`) est
            // conservé tel quel, il n'est pas une donnée de ce dépôt mais la sortie
            // d'un service déjà éprouvé.
            $table->string('source_identity', 500);

            $table->text('guid')->nullable();
            $table->text('url')->nullable();
            $table->text('titre');
            $table->text('auteur')->nullable();
            $table->text('resume')->nullable();
            $table->longText('contenu_html')->nullable();
            $table->timestamp('source_published_at')->nullable();
            $table->timestamp('fetched_at');

            $table->timestamps();

            $table->unique(['rss_source_id', 'source_identity'], 'rss_source_articles_source_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rss_source_articles');
    }
};
