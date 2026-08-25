<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1256 : Human Feedback V1 — le jugement d'un humain sur UNE reponse IA
 * (Article Explorer, les quatre methodes de Roger et le dialogue libre).
 *
 * Petite table fille de la trace produit `ai_interactions` — PAS un
 * framework, PAS un second systeme de telemetrie :
 *  - `ai_interaction_id` FK `ai_interactions` ON DELETE CASCADE : le feedback
 *    herite EXACTEMENT la retention de l'interaction (qui suit la personne,
 *    registre G13/T1254) — jamais l'inverse ;
 *  - `organization_id` = copie EXPLICITE du tenant de l'interaction (meme
 *    pattern que `loop_messages.organization_id`), FK organizations CASCADE ;
 *  - `user_id` FK users CASCADE : l'acteur qui juge ;
 *  - `verdict` ferme (`helpful` | `improve`), `comment` et
 *    `suggested_response` = le contenu de l'HUMAIN, jamais une copie de la
 *    reponse IA (qui vit dans `ai_interactions.response`, par la FK) ;
 *  - unicite (`ai_interaction_id`, `user_id`) : un jugement par personne et
 *    par reponse, mis a jour s'il est redonne.
 *
 * AUCUNE colonne export / training / consent / exportable — meme optionnelle.
 * « Utile » n'est pas un consentement d'entrainement (regle centrale T1255).
 * Le test de garde `TASK1256ExplorerHumanFeedbackTest` ferme la liste des
 * colonnes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_interaction_feedbacks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ai_interaction_id')->constrained('ai_interactions')->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('verdict', 20);
            $table->text('comment')->nullable();
            $table->text('suggested_response')->nullable();
            $table->timestamps();

            $table->unique(['ai_interaction_id', 'user_id']);
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_interaction_feedbacks');
    }
};
