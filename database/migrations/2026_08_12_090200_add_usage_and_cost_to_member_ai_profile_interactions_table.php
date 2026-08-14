<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1132 / IA P1-2 — convergence economique de `member_ai_profile_interactions`.
 *
 * Cette table n'avait ni tokens ni cout : ses interactions etaient invisibles
 * a la question « combien cet appel a-t-il coute ? ». On ajoute le strict
 * necessaire a la convergence P1, rien de plus. Ce n'est PAS un quatrieme
 * registre : les colonnes reprennent les noms et le type deja en place sur les
 * deux autres traces.
 *
 * Conventions reprises des deux autres traces :
 * - `input_tokens` / `output_tokens` : memes noms, meme type unsignedInteger ;
 * - `cost_usd` : decimal, en precision (14,8) comme `admin_ai_interactions` ;
 * - `cost_unknown` : meme semantique tri-etat que les deux autres tables.
 *
 * Difference assumee : ici les colonnes token sont NULLABLE, sans DEFAULT 0.
 * Les deux tables historiques les ont en NOT NULL DEFAULT 0, ce qui rend « 0
 * token » indiscernable de « usage non rapporte ». Comme ces colonnes sont
 * neuves, on ne reproduit pas ce defaut ; corriger les deux tables existantes
 * depasserait le perimetre de P1-2.
 *
 * Etat reel des deux sites d'ecriture au moment de cette TASK :
 * - `App\Livewire\InlineMemberAgent` : reponse rule-based, aucun appel LLM,
 *   donc cout reellement nul et connu ;
 * - `App\Jobs\GenerateAiAgentResponse` : `MemberProfileAgentResponder` ne
 *   remonte aucun usage, donc cout non mesurable des qu'un vrai provider
 *   repond. C'est une limite du contrat actuel, pas une valeur a inventer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_ai_profile_interactions', function (Blueprint $table) {
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->decimal('cost_usd', 14, 8)->nullable();
            $table->boolean('cost_unknown')->nullable();

            $table->index('cost_unknown');
        });
    }

    public function down(): void
    {
        Schema::table('member_ai_profile_interactions', function (Blueprint $table) {
            $table->dropIndex(['cost_unknown']);
            $table->dropColumn(['input_tokens', 'output_tokens', 'cost_usd', 'cost_unknown']);
        });
    }
};
