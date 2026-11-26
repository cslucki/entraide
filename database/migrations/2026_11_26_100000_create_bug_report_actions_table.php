<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1905 — historique des traitements de signalements, et reservation
 * atomique de l'alerte « Securite des enfants ».
 *
 * NON destructive : une table neuve et une colonne nullable.
 *
 * - `bug_report_actions` : qui a fait quoi, quand, avec quelle note INTERNE
 *   (jamais montree aux membres ; distincte de `bug_reports.resolution_notes`,
 *   qui est la reponse lue par l'auteur du signalement). `actor_id` NULL =
 *   action systeme (alerte) ou compte supprime.
 * - `bug_reports.child_safety_alerted_at` : l'alerte est reservee par
 *   `UPDATE ... WHERE child_safety_alerted_at IS NULL` ; une seule execution
 *   obtient la ligne, meme en dispatch concurrent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bug_report_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bug_report_id')->constrained('bug_reports')->cascadeOnDelete();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['bug_report_id', 'created_at']);
        });

        Schema::table('bug_reports', function (Blueprint $table) {
            $table->timestamp('child_safety_alerted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bug_reports', function (Blueprint $table) {
            $table->dropColumn('child_safety_alerted_at');
        });

        Schema::dropIfExists('bug_report_actions');
    }
};
