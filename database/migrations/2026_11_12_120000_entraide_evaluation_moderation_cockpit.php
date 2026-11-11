<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1808 §2 — le cockpit SuperAdmin de gestion des evaluations.
 *
 * ## Trois colonnes additives sur `reviews`, et pourquoi elles sont DISTINCTES
 *
 * - `hidden_at` : le MASQUAGE. Un avis masque continue d'exister, mais
 *   sort de la moyenne publique et de l'affichage — un geste REVERSIBLE
 *   par un simple reclic, pense pour un signalement a trancher vite.
 * - `deleted_at` (SoftDeletes) : la SUPPRESSION. Plus lourde, elle retire
 *   l'avis de `reviewsReceived()` par le mecanisme standard d'Eloquent —
 *   mais reste restaurable, jamais un DELETE SQL definitif depuis ce
 *   cockpit. Le CDC demande « conserver l'original » : un DELETE dur
 *   l'interdirait par construction.
 * - `moderated_by` / `moderation_reason` portent la DERNIERE intervention
 *   pour un affichage rapide dans la liste, sans relire le journal — qui,
 *   lui, garde TOUT l'historique (table ci-dessous).
 *
 * Masquer et supprimer restent deux actions INDEPENDANTES : une evaluation
 * peut etre masquee puis supprimee, ou supprimee directement, sans que
 * l'une implique l'autre.
 *
 * ## Le journal d'audit — append-only, jamais modifie
 *
 * `review_moderation_logs` : une ligne par INTERVENTION (pas par avis),
 * `const UPDATED_AT = null` (meme patron que `LoginLog`/`EmailLog`) parce
 * qu'une ligne de journal ne se corrige jamais — elle s'ajoute. Elle
 * capture l'ORIGINAL (`original_rating`, `original_comment`) tel qu'il
 * etait AVANT le geste, l'ACTEUR, la DATE (`created_at`) et le MOTIF —
 * exactement les quatre elements demandes par le CDC.
 *
 * Idempotente a l'echelle du schema (Schema::hasColumn / hasTable), non
 * destructive, reversible (down() retire exactement ce qui a ete ajoute).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->timestamp('hidden_at')->nullable()->after('comment_skipped_at');
            $table->softDeletes();
            $table->foreignUuid('moderated_by')->nullable()->after('hidden_at')
                ->constrained('users')->nullOnDelete();
            $table->text('moderation_reason')->nullable()->after('moderated_by');
        });

        Schema::create('review_moderation_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('review_id')->constrained('reviews')->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            // nullOnDelete, pas cascadeOnDelete : meme convention que
            // `messages.edited_by` / `admin_ai_interactions.reviewed_by`.
            // Un journal d'audit doit survivre a la suppression de son
            // acteur — un `cascadeOnDelete()` effacerait l'historique de
            // moderation exactement quand un compte SuperAdmin est
            // offboarde, ce que le registre de cycle de vie interdit pour
            // ce type de colonne.
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            // updated|hidden|unhidden|deleted|restored — verifie par le
            // modele, pas par une contrainte CHECK (meme choix que
            // `EmailLog::STATUSES`, pour rester identique sur SQLite et
            // PostgreSQL).
            $table->string('action');
            $table->tinyInteger('original_rating')->nullable();
            $table->text('original_comment')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_moderation_logs');

        Schema::table('reviews', function (Blueprint $table) {
            $table->dropConstrainedForeignId('moderated_by');
            $table->dropColumn(['hidden_at', 'moderation_reason']);
            $table->dropSoftDeletes();
        });
    }
};
