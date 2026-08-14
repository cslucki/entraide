<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A personal Dossier can now be shared, deliberately.
 *
 * Until now `visibility` only ever held `private` or `shared`, and `shared` was
 * not a choice: DossierController forced `private` on every write, and
 * Dossier::syncVisibility() then overwrote it to `shared` as soon as a member
 * existed. The column looked like a setting and behaved like a computed field,
 * which is why nobody could change it.
 *
 * Three modalities from now on, all of them explicit:
 *
 *   private       the owner, plus the people explicitly added to dossier_members
 *   organization  every active member of the same Organization
 *   loop          governed by the rules of one chosen Loop
 *
 * `shared` is migrated to `private`: it never meant "shared with everyone", it
 * meant "has members" — which is exactly what `private` plus dossier_members
 * already expresses.
 *
 * The word `public` is deliberately absent. Nothing here is reachable without
 * being signed in, and reserving that word for a real web visibility keeps the
 * interface honest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dossiers', function (Blueprint $table) {
            // A DIFFERENT column from `loop_id`. `loop_id` says the Loop *owns*
            // this Dossier — it is its root Dossier, it has no personal owner,
            // and the XOR constraint applies. `shared_with_loop_id` says a
            // personal Dossier, still owned by its user, is shared with a Loop.
            // Overloading one column for both would break the XOR and blur two
            // very different things.
            $table->foreignUuid('shared_with_loop_id')->nullable()->after('loop_id')
                ->constrained('loops')->nullOnDelete();
        });

        // No index on (organization_id, visibility): the original
        // create_dossiers_table migration already declares it.

        // `shared` was derived, never chosen. Its meaning is preserved exactly:
        // a Dossier with members stays private, and its members keep their
        // access through dossier_members.
        DB::table('dossiers')->where('visibility', 'shared')->update(['visibility' => 'private']);
    }

    public function down(): void
    {
        Schema::table('dossiers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shared_with_loop_id');
        });

        // `visibility` values are left as they are: rewriting them back to
        // `shared` would guess, and guessing on a rollback is worse than a
        // slightly narrower value.
    }
};
