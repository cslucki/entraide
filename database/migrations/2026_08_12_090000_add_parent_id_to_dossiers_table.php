<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Real sub-folders (TASK-1130, passe 4).
 *
 * Until this migration a Dossier had no hierarchy at all: it was a root, full
 * stop. `parent_id` adds exactly one level of self-reference — additive,
 * reversible, no backfill. Every existing row gets `parent_id = NULL` and
 * stays a perfectly valid root.
 *
 * ## Why the XOR check must change too
 *
 * `dossiers_holder_xor` — added when `loop_id` first appeared — requires
 * exactly one of `owner_id` / `loop_id` on every row. A real child has
 * neither: it is not a holder, it inherits its governance by walking
 * `parent_id` up to the root (`Dossier::governingDossier()`). Left alone, the
 * old constraint would reject every child dossier outright on PostgreSQL.
 *
 * The replacement is layered, not loosened: a root Dossier obeys the exact
 * same rule as before; a child Dossier must have both columns empty. Nothing
 * a root Dossier could do under the old constraint becomes possible under the
 * new one that wasn't already.
 *
 * SQLite cannot add a CHECK constraint to an existing table at all — same
 * limitation the previous migration already documented. The rule lives in
 * `Dossier::assertValidParent()` for every write path, on both engines; the
 * PostgreSQL constraint is defense in depth on the reference runtime, not the
 * only place the rule is written down.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dossiers', function (Blueprint $table) {
            // nullOnDelete, not cascade: destroying a parent must not destroy
            // its children — it promotes them to roots instead, the only
            // non-destructive choice available without a product decision on
            // cascading deletes (out of scope for this pass).
            $table->foreignUuid('parent_id')->nullable()->after('id')
                ->constrained('dossiers')->nullOnDelete();

            $table->index(['organization_id', 'parent_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE dossiers DROP CONSTRAINT IF EXISTS dossiers_holder_xor');
            DB::statement(<<<'SQL'
                ALTER TABLE dossiers ADD CONSTRAINT dossiers_holder_xor CHECK (
                    (parent_id IS NOT NULL AND owner_id IS NULL AND loop_id IS NULL)
                    OR
                    (parent_id IS NULL AND (owner_id IS NULL) <> (loop_id IS NULL))
                )
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE dossiers DROP CONSTRAINT IF EXISTS dossiers_holder_xor');
            DB::statement('ALTER TABLE dossiers ADD CONSTRAINT dossiers_holder_xor CHECK ((owner_id IS NULL) <> (loop_id IS NULL))');
        }

        Schema::table('dossiers', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'parent_id']);
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
