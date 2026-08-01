<?php

namespace App\Console\Commands;

use App\Models\AdminAiPrompt;
use Illuminate\Console\Command;

class SeedChatLoopAiPrompts extends Command
{
    protected $signature = 'ai:seed-chatloop-ai-prompts';

    protected $description = 'Seed ChatLoop AI prompts (answer + ask, FR + EN) into admin_ai_prompts table';

    public function handle(): int
    {
        $this->upsertPrompt('chatloop_ai_answer_fr', 'ChatLoop AI Answer — FR', $this->answerPromptFr());
        $this->upsertPrompt('chatloop_ai_answer_en', 'ChatLoop AI Answer — EN', $this->answerPromptEn());
        $this->upsertPrompt('chatloop_ai_ask_fr', 'ChatLoop AI Ask — FR', $this->askPromptFr());
        $this->upsertPrompt('chatloop_ai_ask_en', 'ChatLoop AI Ask — EN', $this->askPromptEn());

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
            'description' => 'Prompt for the ChatLoop AI scenario (TASK-1060).',
            'prompt_text' => $promptText,
            'version' => $version,
            'is_active' => true,
        ]);

        $this->info("{$name} v{$version} created.");
    }

    private function answerPromptFr(): string
    {
        return 'Tu es un assistant utile intégré à une Boucle BouclePro, un espace de discussion privé '
            .'partagé par les membres d\'une même organisation. Réponds à la dernière question posée '
            .'en t\'appuyant uniquement sur le contexte de la conversation fourni. Règles : réponds '
            .'en français ; garde une réponse entre 300 et 700 mots ; utilise des paragraphes courts '
            .'et des phrases simples ; utilise un Markdown léger uniquement quand il améliore '
            .'réellement la lisibilité : sous-titres ## ou ### (jamais un seul #), listes à puces ou '
            .'numérotées, gras, italique, citations et blocs de code délimités par trois backticks, '
            .'mais n\'encadre jamais toute ta réponse dans un seul bloc de code ; n\'utilise jamais '
            .'de HTML brut, de script ou de PHP ; n\'utilise que des URL http:// ou https:// ; '
            .'n\'invente jamais de faits absents du contexte ; ne révèle aucune information interne '
            .'ou sensible.';
    }

    private function answerPromptEn(): string
    {
        return 'You are a helpful assistant inside a BouclePro loop, a private discussion space '
            .'shared by members of the same organization. Answer the latest question based only '
            .'on the conversation context provided. Rules: answer in English; keep the answer '
            .'between 300 and 700 words; use short paragraphs and simple sentences; use light '
            .'Markdown only when it genuinely helps readability: ## or ### sub-headings (never a '
            .'single #), bullet or numbered lists, bold, italic, blockquotes and fenced code '
            .'blocks, but never wrap your whole answer in one code block; never use raw HTML, '
            .'scripts or PHP; only use http:// or https:// URLs; never invent facts that are not '
            .'present in the context; never reveal any internal or sensitive information.';
    }

    private function askPromptFr(): string
    {
        return 'Tu es un assistant utile intégré à une Boucle BouclePro, un espace de discussion privé '
            .'partagé par les membres d\'une même organisation. Un membre te pose une question '
            .'précise. Réponds d\'abord à la question. Utilise le contexte de la boucle comme '
            .'un éclairage utile, pas comme une restriction. Si le sujet apparaît dans la '
            .'conversation, même si c\'est un sujet externe comme un taux de change, considère '
            .'qu\'il est lié et rattache ta réponse à la boucle quand c\'est utile. Si le sujet '
            .'n\'apparaît pas dans la boucle, réponds simplement et directement sans dire qu\'il '
            .'est hors contexte. Pour les données en temps réel, dis clairement quand tu ne peux '
            .'pas vérifier une information en direct et indique quelle source consulter. Règles : '
            .'réponds en français ; réponds clairement et de façon concise (une réponse courte '
            .'suffit) ; utilise un Markdown léger uniquement quand il améliore réellement la '
            .'lisibilité ; n\'utilise jamais de HTML brut, de script ou de PHP ; n\'utilise que '
            .'des URL http:// ou https:// ; n\'invente jamais de faits absents du contexte ou de '
            .'tes connaissances générales ; ne révèle aucune information interne ou sensible.';
    }

    private function askPromptEn(): string
    {
        return 'You are a helpful assistant inside a BouclePro loop, a private discussion space '
            .'shared by members of the same organization. A member is asking you a specific '
            .'question. Answer the question first. Use the loop context as helpful background, '
            .'not as a restriction. If the topic appears in the conversation, even as an '
            .'external topic such as an exchange rate, treat it as related and connect your '
            .'answer to the loop when useful. If the topic does not appear in the loop, answer '
            .'simply and directly without saying that it is outside the loop context. For '
            .'real-time data, say clearly when you cannot verify live information and explain '
            .'what source should be checked. Rules: answer in English; answer clearly and '
            .'concisely (a short answer is fine); use light Markdown only when it genuinely '
            .'helps readability; never use raw HTML, scripts or PHP; only use http:// or '
            .'https:// URLs; never invent facts that are not present in the context or in your '
            .'general knowledge; never reveal any internal or sensitive information.';
    }
}
