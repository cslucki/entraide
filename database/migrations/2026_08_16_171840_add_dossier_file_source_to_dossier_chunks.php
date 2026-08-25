<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TASK-1216 : dossier_chunks accueille une seconde source (fichier
     * TXT/Markdown) a cote de l'Article. Choix delibere : pas de colonne
     * polymorphique generique — `dossier_file_id` nullable, FK dediee, au
     * meme style que `blog_post_id`. `blog_post_id` devient nullable (une
     * ligne de chunk fichier ne porte pas d'Article). L'invariant "exactement
     * une des deux colonnes est renseignee" est porte par les indexeurs
     * applicatifs (DossierArticleIndexer / DossierFileIndexer), pas par une
     * contrainte CHECK — coherent avec le reste du schema, qui n'en pose pas
     * non plus pour ses propres invariants applicatifs.
     */
    public function up(): void
    {
        Schema::table('dossier_chunks', function (Blueprint $table) {
            $table->uuid('blog_post_id')->nullable()->change();

            $table->foreignUuid('dossier_file_id')
                ->nullable()
                ->after('blog_post_id')
                ->constrained('dossier_files')
                ->cascadeOnDelete();

            $table->index('dossier_file_id');

            // NULL n'est jamais egal a NULL dans une contrainte unique
            // (Postgres et SQLite) : l'ancienne contrainte sur blog_post_id
            // n'empeche donc rien entre deux lignes fichier (toutes deux
            // NULL sur blog_post_id). Une contrainte dediee est necessaire
            // pour l'identite d'un chunk fichier.
            $table->unique([
                'dossier_id',
                'dossier_file_id',
                'chunk_index',
                'embedding_provider',
                'embedding_model',
            ], 'dossier_chunks_unique_file_chunk_identity');
        });
    }

    public function down(): void
    {
        // Revue MASTER (TASK-1216) : une ligne de chunk fichier a
        // `blog_post_id` NULL. Remettre `blog_post_id` NOT NULL avec de
        // telles lignes encore presentes echoue (ou pire, corromprait des
        // lignes fichier en leur inventant un blog_post_id). Ce rollback
        // n'a jamais vocation a migrer un chunk fichier vers un Article —
        // il retire seulement ce que cette migration a rendu possible.
        DB::table('dossier_chunks')->whereNotNull('dossier_file_id')->delete();

        Schema::table('dossier_chunks', function (Blueprint $table) {
            $table->dropUnique('dossier_chunks_unique_file_chunk_identity');
            // SQLite reconstruit la table pour un DROP COLUMN : l'index
            // simple pose par `up()` doit disparaitre avant la colonne, sinon
            // la reconstruction echoue en cherchant une colonne deja partie.
            $table->dropIndex(['dossier_file_id']);
            $table->dropConstrainedForeignId('dossier_file_id');
            $table->uuid('blog_post_id')->nullable(false)->change();
        });
    }
};
