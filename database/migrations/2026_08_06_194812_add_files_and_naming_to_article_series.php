<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Une Serie peut desormais contenir des fichiers, et porter un nom.
 *
 * Trois verrous tombaient jusqu'ici :
 *
 * 1. `article_series_items.blog_post_id` etait NOT NULL — un fichier ne pouvait
 *    pas entrer dans une Serie ;
 * 2. `article_series.root_blog_post_id` etait NOT NULL — une Serie de fichiers
 *    n'avait donc pas d'existence possible, faute d'Article racine ;
 * 3. aucune colonne de nom — une Serie sans racine n'avait rien a afficher.
 *
 * Tout est **ajoute**, rien n'est retire. Les quatre Series et les dix items
 * existants restent valides tels quels : leur `blog_post_id` est renseigne,
 * leur racine aussi, et `name` reste nul — l'affichage retombe alors sur le
 * titre de la racine.
 *
 * Les `unique` sur les deux colonnes d'item sont conserves et portent la regle
 * du MVP : **un contenu n'appartient qu'a une seule Serie**. En SQLite comme en
 * PostgreSQL, plusieurs NULL ne s'egalent pas — la colonne devenue nullable
 * garde donc son unicite sans index partiel.
 *
 * Pas de contrainte CHECK pour « exactement l'un des deux » : sa syntaxe et son
 * comportement different entre les deux moteurs, et l'invariant est tenu par le
 * service, verifie par les tests. Une contrainte qu'on ne peut pas garantir
 * identique des deux cotes vaut moins qu'une regle testee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('article_series', function (Blueprint $table) {
            // Le nom d'une Serie. Nul par defaut : une Serie qui a une racine
            // prend son titre, et les quatre Series existantes n'ont donc rien
            // a reprendre.
            $table->string('name')->nullable()->after('dossier_id');
        });

        Schema::table('article_series', function (Blueprint $table) {
            $table->uuid('root_blog_post_id')->nullable()->change();
        });

        Schema::table('article_series_items', function (Blueprint $table) {
            $table->foreignUuid('dossier_file_id')
                ->nullable()
                ->after('blog_post_id')
                ->constrained('dossier_files')
                ->cascadeOnDelete();

            $table->unique('dossier_file_id');
        });

        Schema::table('article_series_items', function (Blueprint $table) {
            $table->uuid('blog_post_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Le retour arriere n'est sur que si aucune Serie n'utilise ce que la
        // migration a ouvert. On le verifie plutot que de supprimer des lignes :
        // une migration qui detruit des donnees en redescendant n'est pas
        // reversible, elle est destructrice.
        $itemsFichiers = \Illuminate\Support\Facades\DB::table('article_series_items')
            ->whereNotNull('dossier_file_id')
            ->count();

        $seriesSansRacine = \Illuminate\Support\Facades\DB::table('article_series')
            ->whereNull('root_blog_post_id')
            ->count();

        if ($itemsFichiers > 0 || $seriesSansRacine > 0) {
            throw new RuntimeException(
                "Retour arriere refuse : {$itemsFichiers} item(s) de fichier et "
                ."{$seriesSansRacine} Serie(s) sans racine existent. Les retirer "
                .'depuis l\'application avant de redescendre cette migration.'
            );
        }

        Schema::table('article_series_items', function (Blueprint $table) {
            $table->uuid('blog_post_id')->nullable(false)->change();
        });

        Schema::table('article_series_items', function (Blueprint $table) {
            $table->dropUnique(['dossier_file_id']);
            $table->dropConstrainedForeignId('dossier_file_id');
        });

        Schema::table('article_series', function (Blueprint $table) {
            $table->uuid('root_blog_post_id')->nullable(false)->change();
        });

        Schema::table('article_series', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
