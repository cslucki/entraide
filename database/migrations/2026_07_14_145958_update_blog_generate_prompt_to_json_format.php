<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $newPrompt = "Rédige un article de blog en te basant sur le titre et le résumé fournis. Tu dois retourner un objet JSON unique avec exactement ces 3 champs :\n- \"title\" : le titre amélioré de l'article (string)\n- \"summary\" : un résumé percutant de 1 à 2 phrases (string)\n- \"content\" : le corps de l'article en HTML structuré avec des balises h2, h3, p, ul, li (string). Maximum 500 mots. Pas de balise h1 ni de h2 avec le titre.\n\nRetourne UNIQUEMENT le JSON brut, sans markdown, sans introduction, sans texte avant ou après.\n\nTitre fourni : %s\nRésumé fourni : %s";

        DB::table('admin_ai_prompts')
            ->where('scenario_id', 'blog_generate')
            ->where('version', 2)
            ->update(['prompt_text' => $newPrompt]);
    }

    public function down(): void
    {
        $oldPrompt = "Rédige un article de blog structuré en HTML qui correspond au titre et au résumé suivants. Utilise des balises HTML valides (h2, h3, p, ul, li, etc.). Ta réponse doit faire 500 mots maximum. Réponds UNIQUEMENT avec le contenu HTML, sans introduction, sans conclusion. NE reproduis PAS le titre (pas de h1/h2 avec le titre) et NE reproduis PAS le résumé (pas de premier paragraphe avec le résumé). Le titre et le résumé sont déjà gérés séparément.\n\nTitre : %s\nRésumé : %s";

        DB::table('admin_ai_prompts')
            ->where('scenario_id', 'blog_generate')
            ->where('version', 2)
            ->update(['prompt_text' => $oldPrompt]);
    }
};
