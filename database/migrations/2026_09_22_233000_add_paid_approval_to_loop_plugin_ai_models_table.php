<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1622 — le mode « Payant approuve » a cote du « Gratuit verifie ».
 *
 * Une ligne porte desormais son TYPE :
 *
 *   - `free_verified` (defaut, toutes les lignes existantes) : le mecanisme
 *     TASK-1617 inchange — preuve de gratuite `verified_free_at`, peremption
 *     15 min, renouvellement strict ;
 *   - `paid_approved` : un modele PAYANT explicitement approuve par un
 *     SuperAdmin. Pas de preuve de gratuite — c'est un autre contrat : le
 *     slug doit etre tarifie au catalogue STATIQUE (`config/ai_pricing.php`),
 *     et l'approbation porte son auteur et sa date.
 *
 * AUDIT TRAIL — idiome maison (`loop_join_requests.decided_*`,
 * `course_assignment_submissions.reviewed_*`) : `approved_at` timestamp et
 * `approved_by` FK users nullOnDelete COTE A COTE. Une approbation sans
 * auteur ne serait pas une approbation ; l'auteur supprime est detache, la
 * decision survit anonymement. La FK est DECLAREE au
 * `UserDataLifecycleRegistry` (garde T1614/T1617 — oubliee deux fois, plus
 * jamais).
 *
 * `timestamp` et PAS `timestampTz` — meme piege que `verified_free_at`
 * (migration 2026_09_21_190000) : en PostgreSQL, `timestampTz` rend la
 * valeur dans le fuseau de la SESSION alors que `Carbon::now()` ecrit de
 * l'UTC ; toute comparaison de fraicheur/audit lirait 2 h dans le passe en
 * CEST, invisible en SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loop_plugin_ai_models', function (Blueprint $table) {
            // Le discriminant. Defaut = l'existant : aucune ligne actuelle ne
            // change de sens au deploiement.
            $table->string('model_type', 20)->default('free_verified');
            $table->timestamp('approved_at')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('loop_plugin_ai_models', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['model_type', 'approved_at']);
        });
    }
};
