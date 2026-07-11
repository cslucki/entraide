<?php

use App\Models\AdminAiPrompt;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $methods = [
            'explorer' => ['fr' => 'Explorer', 'en' => 'Explore'],
            'clarifier' => ['fr' => 'Clarifier', 'en' => 'Clarify'],
            'slow_down' => ['fr' => 'Ralentir', 'en' => 'Slow down'],
            'invent' => ['fr' => 'Inventer', 'en' => 'Invent'],
        ];

        foreach ($methods as $key => $labels) {
            foreach (['fr', 'en'] as $locale) {
                $scenarioId = "blog_method_selection_{$key}_{$locale}";

                AdminAiPrompt::updateOrCreate([
                    'scenario_id' => $scenarioId,
                    'version' => 1,
                ], [
                    'scenario_id' => $scenarioId,
                    'name' => "SuperBlog — Méthode IA sélection — {$labels[$locale]} — ".strtoupper($locale),
                    'description' => 'Questionnement court d\'un passage sélectionné, destiné à une suggestion d\'annotation validée par un humain.',
                    'prompt_text' => $this->promptText($locale),
                    'is_active' => true,
                    'metadata' => [
                        'method' => $key,
                        'locale' => $locale,
                        'scope' => 'selection',
                    ],
                ]);
            }
        }
    }

    public function down(): void
    {
        AdminAiPrompt::whereIn('scenario_id', [
            'blog_method_selection_explorer_fr',
            'blog_method_selection_explorer_en',
            'blog_method_selection_clarifier_fr',
            'blog_method_selection_clarifier_en',
            'blog_method_selection_slow_down_fr',
            'blog_method_selection_slow_down_en',
            'blog_method_selection_invent_fr',
            'blog_method_selection_invent_en',
        ])->delete();
    }

    private function promptText(string $locale): string
    {
        if ($locale === 'en') {
            return "You are an editorial assistant. Analyze ONLY the selected passage according to the requested method. Return a very short, human response, not a general chat answer. Use plain text only: no Markdown, no HTML, no asterisks, no Markdown headings. Target 300-500 characters. Use exactly these plain-text headings: Observation, Question, Path. Give one main path only.\n\nMethod: %s\nArticle title: %s\nSelected passage: %s\nContext before: %s\nContext after: %s";
        }

        return "Tu es un assistant éditorial. Analyse UNIQUEMENT le passage sélectionné selon la méthode demandée. Retourne une réponse très courte et humaine, pas une réponse de chat général. Utilise uniquement du texte brut : aucun Markdown, aucun HTML, aucun astérisque, aucun titre Markdown. Vise 300 à 500 caractères. Utilise exactement ces titres textuels : Observation, Question, Piste. Donne une seule piste principale.\n\nMéthode : %s\nTitre de l'article : %s\nPassage sélectionné : %s\nContexte avant : %s\nContexte après : %s";
    }
};
