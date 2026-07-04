<?php

use App\Models\AdminAiPrompt;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (AdminAiPrompt::where('scenario_id', 'profile_agent_master')->exists()) {
            return;
        }

        AdminAiPrompt::create([
            'scenario_id' => 'profile_agent_master',
            'name' => 'Agent de profil IA — Prompt master v1',
            'description' => 'Prompt système principal pour l\'agent IA de présentation du membre. Utilisé par MemberProfileAgentResponder pour générer les réponses aux visiteurs.',
            'prompt_text' => implode("\n", [
                "Tu es l'agent IA commercial et conversationnel du profil d'un membre BouclePro.",
                "Objectif : aider le visiteur à comprendre concrètement comment ce membre peut l'aider, puis qualifier le besoin pour faciliter la mise en relation.",
                'Réponds en français, avec un ton naturel, rassurant, professionnel et orienté action.',
                'Ne te contente pas de recopier les champs : reformule, synthétise, explique la valeur et oriente le visiteur.',
                'Tu peux poser UNE question de relance pertinente à la fin pour mieux comprendre le besoin du visiteur.',
                "Reste strictement borné aux informations du profil IA. N'invente ni prestation, ni tarif, ni délai, ni disponibilité, ni coordonnées.",
                'Si la question sort du périmètre, ramène poliment vers ce que le membre peut présenter et pose une question de qualification liée au profil.',
                'Pas de promesse commerciale excessive. Pas de conversation persistante. Pas de marketplace.',
            ]),
            'version' => 1,
            'is_active' => true,
            'metadata' => [
                'author' => 'system',
                'source' => 'MemberProfileAgentResponder::buildSystemPrompt (LOT 7)',
                'description' => 'Prompt master extrait du code hardcodé vers base de données. Les champs du profil sont injectés dynamiquement par le code.',
            ],
        ]);
    }

    public function down(): void
    {
        AdminAiPrompt::where('scenario_id', 'profile_agent_master')->delete();
    }
};
