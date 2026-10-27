<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1693 bug Q — tracer QUI a modifie un message, et quand.
 *
 * L'administration peut desormais corriger le corps d'un message. Un
 * administrateur qui reecrit les mots de quelqu'un d'autre ne doit PAS pouvoir
 * le faire en silence : la trace est donc la condition de la capacite, pas un
 * ornement.
 *
 * - `loop_messages` portait deja `edited_at` (TASK-1298, edition par l'auteur),
 *   affiche « modifie » dans le ChatLoop. Il lui manquait le DECIDEUR : une
 *   edition par l'auteur et une edition par la moderation s'y confondaient.
 * - `messages` (conversations d'echange) n'avait ni l'un ni l'autre, et ce
 *   modele n'a meme pas d'`updated_at` (`$timestamps = false`) : sans colonne,
 *   une edition y serait rigoureusement invisible.
 *
 * Deux colonnes nullables et une clef etrangere `nullOnDelete` : additif,
 * aucune donnee existante touchee, aucun defaut a recalculer. Les lignes
 * anterieures gardent `edited_at = NULL`, c'est-a-dire « jamais modifie » —
 * ce qui est vrai.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            if (! Schema::hasColumn('messages', 'edited_at')) {
                $table->timestamp('edited_at')->nullable()->after('created_at');
            }

            if (! Schema::hasColumn('messages', 'edited_by')) {
                $table->foreignUuid('edited_by')->nullable()->after('edited_at')->constrained('users')->nullOnDelete();
            }
        });

        Schema::table('loop_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('loop_messages', 'edited_by')) {
                $table->foreignUuid('edited_by')->nullable()->after('edited_at')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('loop_messages', function (Blueprint $table) {
            if (Schema::hasColumn('loop_messages', 'edited_by')) {
                $table->dropForeign(['edited_by']);
                $table->dropColumn('edited_by');
            }
        });

        Schema::table('messages', function (Blueprint $table) {
            if (Schema::hasColumn('messages', 'edited_by')) {
                $table->dropForeign(['edited_by']);
                $table->dropColumn('edited_by');
            }

            if (Schema::hasColumn('messages', 'edited_at')) {
                $table->dropColumn('edited_at');
            }
        });
    }
};
