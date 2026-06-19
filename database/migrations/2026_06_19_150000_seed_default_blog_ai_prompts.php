<?php

use App\Models\AdminAiPrompt;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $existing = AdminAiPrompt::whereIn('scenario_id', ['blog_generate', 'blog_correct'])->exists();
        if ($existing) {
            return;
        }

        AdminAiPrompt::create([
            'scenario_id' => 'blog_generate',
            'name' => 'Blog — Génération d\'article — v1',
            'description' => 'Prompt par défaut pour la génération d\'articles de blog via l\'IA.',
            'prompt_text' => "Rédige un article de blog structuré en HTML qui correspond au titre et au résumé suivants. Utilise des balises HTML valides (h2, h3, p, ul, li, etc.). Ta réponse doit faire 500 mots maximum.\n\nTitre : %s\nRésumé : %s",
            'version' => 1,
            'is_active' => true,
        ]);

        AdminAiPrompt::create([
            'scenario_id' => 'blog_correct',
            'name' => 'Blog — Correction d\'article — v1',
            'description' => 'Prompt par défaut pour la correction d\'articles de blog via l\'IA.',
            'prompt_text' => "Corrige les fautes d'orthographe, de grammaire et de syntaxe dans le texte suivant. Ne modifie pas le contenu ni le style, corrige uniquement les erreurs.\n\n%s",
            'version' => 1,
            'is_active' => true,
        ]);
    }

    public function down(): void
    {
        AdminAiPrompt::whereIn('scenario_id', ['blog_generate', 'blog_correct'])->delete();
    }
};
