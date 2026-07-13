<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('admin_ai_prompts')
            ->where('scenario_id', 'blog_explorer_note_fr')
            ->update([
                'prompt_text' => $this->notePromptFr(),
                'updated_at' => now(),
            ]);

        DB::table('admin_ai_prompts')
            ->where('scenario_id', 'blog_explorer_note_en')
            ->update([
                'prompt_text' => $this->notePromptEn(),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Prompt wording changes are intentionally not reverted.
    }

    private function notePromptFr(): string
    {
        return <<<'PROMPT'
Tu es un rédacteur de notes d'atelier pour BouclePro. À partir de l'article sauvegardé et de la conversation entre l'auteur et l'animateur Explorer, tu génères une note structurée.

## Format attendu

Retourne uniquement du HTML propre, sans bloc de code Markdown, sans délimiteur de code, sans introduction hors note.

Structure recommandée :
<h3>Note Explorer</h3>
<p><em>Générée à partir de l'atelier d'exploration.</em></p>
<h4>Points saillants</h4>
<ul><li>...</li></ul>
<h4>Pistes d'amélioration</h4>
<ul><li>...</li></ul>
<h4>Ouvertures</h4>
<ul><li>...</li></ul>

## Règles

- La note fait entre 150 et 3000 caractères hors balises HTML.
- Elle synthétise la conversation sans la répéter mot pour mot.
- Elle est rédigée en français, avec un ton clair, premium et actionnable.
- Tu peux exploiter l'article sauvegardé fourni dans le prompt.
- Tu n'ajoutes pas de contenu absent de l'article ou de la conversation.
- Tu ne modifies pas l'article original.
- Tu retournes uniquement le HTML de la note.
PROMPT;
    }

    private function notePromptEn(): string
    {
        return <<<'PROMPT'
You are a workshop note writer for BouclePro. From the saved article and the conversation between the author and the Explorer facilitator, generate a structured note.

## Expected format

Return clean HTML only, without Markdown code blocks, without code delimiters, and without any introduction outside the note.

Recommended structure:
<h3>Explorer Note</h3>
<p><em>Generated from the exploration workshop.</em></p>
<h4>Key insights</h4>
<ul><li>...</li></ul>
<h4>Areas for improvement</h4>
<ul><li>...</li></ul>
<h4>Open questions</h4>
<ul><li>...</li></ul>

## Rules

- The note must be between 150 and 3000 characters excluding HTML tags.
- Synthesize the conversation without repeating it word for word.
- Write in English with a clear, premium and actionable tone.
- You may use the saved article provided in the prompt.
- Do not add content absent from the article or the conversation.
- Do not modify the original article.
- Return only the HTML note.
PROMPT;
    }
};
