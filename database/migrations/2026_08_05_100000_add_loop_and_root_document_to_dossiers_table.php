<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A Dossier can now belong to a Loop, and designate its root document.
 *
 * Until now `dossiers.owner_id` was a mandatory FK to `users`: a Dossier always
 * belonged to a person. A Loop has several owners since TASK-1079, and they
 * change — so a Loop's Dossier has no natural personal owner.
 *
 * `owner_id` therefore becomes nullable and `loop_id` appears beside it. The
 * pair is constrained: exactly one of the two is filled, never both, never
 * neither. That is a stricter guarantee than the one it replaces.
 *
 * `root_blog_post_id` designates the Dossier's reference document. The pattern
 * is not new — `article_series.root_blog_post_id` already does exactly this for
 * Series, with the same uniqueness and the same "belongs to this container"
 * check (DossierSeriesController::ensureBlogPostBelongsToDossier).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dossiers', function (Blueprint $table) {
            // Nullable now that a Loop can be the holder. The XOR check below
            // is what keeps this from weakening the model.
            $table->foreignUuid('owner_id')->nullable()->change();

            $table->foreignUuid('loop_id')->nullable()->after('owner_id')
                ->constrained('loops')->cascadeOnDelete();

            // One root Dossier per Loop, guaranteed by the index rather than by
            // the discipline of callers.
            $table->unique('loop_id');

            // nullOnDelete, never cascade: deleting the article must not take
            // the Dossier and everything in it with it.
            $table->foreignUuid('root_blog_post_id')->nullable()->after('loop_id')
                ->constrained('blog_posts')->nullOnDelete();
        });

        // A CHECK constraint is not expressible through the schema builder, and
        // SQLite cannot add one to an existing table at all. On PostgreSQL —
        // the reference runtime — the database refuses a malformed row outright.
        // Elsewhere the same rule is held by DossierRootDocumentService and
        // proven by its tests.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE dossiers ADD CONSTRAINT dossiers_holder_xor CHECK ((owner_id IS NULL) <> (loop_id IS NULL))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE dossiers DROP CONSTRAINT IF EXISTS dossiers_holder_xor');
        }

        Schema::table('dossiers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('root_blog_post_id');
            $table->dropUnique(['loop_id']);
            $table->dropConstrainedForeignId('loop_id');
        });

        // owner_id is deliberately left nullable on rollback: restoring NOT NULL
        // would fail on any Dossier created for a Loop in the meantime, and
        // failing a rollback is worse than a slightly looser column.
    }
};
