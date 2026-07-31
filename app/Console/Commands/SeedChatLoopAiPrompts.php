<?php

namespace App\Console\Commands;

use App\Models\AdminAiPrompt;
use Illuminate\Console\Command;

class SeedChatLoopAiPrompts extends Command
{
    protected $signature = 'ai:seed-chatloop-ai-prompts';

    protected $description = 'Seed ChatLoop AI answer prompts (FR + EN) into admin_ai_prompts table';

    public function handle(): int
    {
        $this->upsertPrompt('chatloop_ai_answer_fr', 'ChatLoop AI Answer — FR', $this->promptFr());
        $this->upsertPrompt('chatloop_ai_answer_en', 'ChatLoop AI Answer — EN', $this->promptEn());

        $this->info('ChatLoop AI prompts are now editable at /admin/ai-prompts.');

        return self::SUCCESS;
    }

    private function upsertPrompt(string $scenarioId, string $name, string $promptText): void
    {
        $active = AdminAiPrompt::active()
            ->where('scenario_id', $scenarioId)
            ->orderByDesc('version')
            ->first();

        if ($active) {
            $this->info("{$name} — an active version already exists (unchanged).");

            return;
        }

        $maxVersion = AdminAiPrompt::where('scenario_id', $scenarioId)->max('version') ?? 0;
        $version = $maxVersion + 1;

        AdminAiPrompt::create([
            'scenario_id' => $scenarioId,
            'name' => $name,
            'description' => 'Prompt for the ChatLoop AI answer scenario (TASK-1059 MT-1).',
            'prompt_text' => $promptText,
            'version' => $version,
            'is_active' => true,
        ]);

        $this->info("{$name} v{$version} created.");
    }

    private function promptFr(): string
    {
        return 'Tu es un assistant utile intégré à une Boucle BouclePro, un espace de discussion privé '
            .'partagé par les membres d\'une même organisation. Réponds à la dernière question posée '
            .'en t\'appuyant uniquement sur le contexte de la conversation fourni. Règles : réponds '
            .'en français ; garde une réponse entre 300 et 700 mots ; utilise des paragraphes courts '
            .'et des phrases simples ; ne réponds jamais avec du HTML, du Markdown ou du script ; '
            .'n\'invente jamais de faits absents du contexte ; ne révèle aucune information interne '
            .'ou sensible.';
    }

    private function promptEn(): string
    {
        return 'You are a helpful assistant inside a BouclePro loop, a private discussion space '
            .'shared by members of the same organization. Answer the latest question based only '
            .'on the conversation context provided. Rules: answer in English; keep the answer '
            .'between 300 and 700 words; use short paragraphs and simple sentences; never reply '
            .'with HTML, Markdown or script; never invent facts that are not present in the '
            .'context; never reveal any internal or sensitive information.';
    }
}
