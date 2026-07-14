<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $existing = DB::table('admin_ai_prompts')
            ->where('scenario_id', 'blog_generate')
            ->where('is_active', true)
            ->orderBy('version', 'desc')
            ->first();

        if (! $existing) {
            return;
        }

        DB::table('admin_ai_prompts')
            ->where('id', $existing->id)
            ->update(['is_active' => false]);

        DB::table('admin_ai_prompts')->insert([
            'id' => (string) Str::uuid(),
            'scenario_id' => 'blog_generate',
            'name' => 'Blog — Génération d\'article (sans titre/résumé dans le corps)',
            'prompt_text' => "Rédige un article de blog structuré en HTML qui correspond au titre et au résumé suivants. Utilise des balises HTML valides (h2, h3, p, ul, li, etc.). Ta réponse doit faire 500 mots maximum. Réponds UNIQUEMENT avec le contenu HTML, sans introduction, sans conclusion. NE reproduis PAS le titre (pas de h1/h2 avec le titre) et NE reproduis PAS le résumé (pas de premier paragraphe avec le résumé). Le titre et le résumé sont déjà gérés séparément.\n\nTitre : %s\nRésumé : %s",
            'version' => ($existing->version ?? 1) + 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('admin_ai_prompts')
            ->where('scenario_id', 'blog_generate')
            ->where('version', '>', 1)
            ->delete();

        DB::table('admin_ai_prompts')
            ->where('scenario_id', 'blog_generate')
            ->where('is_active', false)
            ->orderBy('version', 'desc')
            ->limit(1)
            ->update(['is_active' => true]);
    }
};
