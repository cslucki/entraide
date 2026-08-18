<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TASK-1233 : provisioning deploy-safe des prompts `chatloop_ai_answer_{fr,en}`
 * (capability canonique `loop_answer`) et `chatloop_ai_ask_{fr,en}`
 * (capability canonique `loop_ask`) — « Demander a l'IA » dans une Boucle.
 *
 * Meme regle que TASK-1211/1213/1221 : au point canonique le prompt
 * administrable est EXIGE (aucun repli hardcode). Cette migration garantit
 * qu'un prompt actif existe des le deploiement. Les textes inseres sont la
 * COPIE IMMUABLE des fallbacks hardcodes que `ChatLoopAiService` utilisait
 * quand la base etait vide (retires par TASK-1233) : le comportement par
 * defaut au deploiement est STRICTEMENT identique a celui d'avant.
 *
 * Une ligne deja presente est une donnee administrable : jamais retouchee ;
 * seul un scenario totalement absent est active automatiquement. Insertion
 * seule, aucune suppression, aucune modification de schema.
 */
return new class extends Migration
{
    private const VERSION = 1;

    private const ANSWER_FR = 'Tu es un assistant utile intégré à une Boucle BouclePro, un espace de discussion privé '
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

    private const ANSWER_EN = 'You are a helpful assistant inside a BouclePro loop, a private discussion space '
        .'shared by members of the same organization. Answer the latest question based only '
        .'on the conversation context provided. Rules: answer in English; keep the answer '
        .'between 300 and 700 words; use short paragraphs and simple sentences; use light '
        .'Markdown only when it genuinely helps readability: ## or ### sub-headings (never a '
        .'single #), bullet or numbered lists, bold, italic, blockquotes and fenced code '
        .'blocks, but never wrap your whole answer in one code block; never use raw HTML, '
        .'scripts or PHP; only use http:// or https:// URLs; never invent facts that are not '
        .'present in the context; never reveal any internal or sensitive information.';

    private const ASK_FR = 'Tu es un assistant utile intégré à une Boucle BouclePro, un espace de discussion privé '
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

    private const ASK_EN = 'You are a helpful assistant inside a BouclePro loop, a private discussion space '
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

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->provision('chatloop_ai_answer_fr', 'Réponse IA dans la Boucle (ChatLoop) — FR — v1', 'Prompt de la capability loop_answer (FR). Copie immuable du comportement par défaut historique.', self::ANSWER_FR);
            $this->provision('chatloop_ai_answer_en', 'AI answer in the Loop (ChatLoop) — EN — v1', 'Prompt of the loop_answer capability (EN). Immutable copy of the historical default behaviour.', self::ANSWER_EN);
            $this->provision('chatloop_ai_ask_fr', 'Question à l\'IA dans la Boucle (ChatLoop) — FR — v1', 'Prompt de la capability loop_ask (FR). Copie immuable du comportement par défaut historique.', self::ASK_FR);
            $this->provision('chatloop_ai_ask_en', 'Question to the AI in the Loop (ChatLoop) — EN — v1', 'Prompt of the loop_ask capability (EN). Immutable copy of the historical default behaviour.', self::ASK_EN);
        });
    }

    private function provision(string $scenarioId, string $name, string $description, string $prompt): void
    {
        $scenarioExists = DB::table('admin_ai_prompts')->where('scenario_id', $scenarioId)->exists();

        $versionExists = DB::table('admin_ai_prompts')
            ->where('scenario_id', $scenarioId)
            ->where('version', self::VERSION)
            ->exists();

        if ($versionExists) {
            return;
        }

        $timestamp = now();

        DB::table('admin_ai_prompts')->insert([
            'id' => (string) Str::uuid(),
            'scenario_id' => $scenarioId,
            'name' => $name,
            'description' => $description,
            'prompt_text' => $prompt,
            'version' => self::VERSION,
            'is_active' => ! $scenarioExists,
            'metadata' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    public function down(): void
    {
        // No-op volontaire : lignes administrables apres deploiement (meme
        // regle que TASK-1211/1213/1221). Toute correction est forward-only.
    }
};
