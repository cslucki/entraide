<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TASK-1221 : provisioning deploy-safe des prompts `chatloop_ai_summarize_fr`
 * et `chatloop_ai_summarize_en` (capability `loop_summary`).
 *
 * Jusqu'ici, AUCUNE migration ne provisionnait ces scenarios : seul le command
 * `ai:seed-chatloop-prompts` le faisait, sans garantie d'execution. Le chemin
 * canonique de `loop_summary` retombait alors SILENCIEUSEMENT sur un prompt
 * hardcode — un contournement invisible d'AdminAiPrompt pour une capability
 * declaree administrable. TASK-1221 exige desormais un prompt DB actif au
 * point canonique ; cette migration garantit qu'il existe.
 *
 * Les textes inseres sont la COPIE IMMUABLE des fallbacks hardcodes que le
 * code utilisait quand la base etait vide : le comportement par defaut au
 * deploiement est STRICTEMENT identique a celui d'avant.
 *
 * Meme regle que TASK-1211/TASK-1213 : une ligne deja presente est une donnee
 * administrable, la migration n'y retouche jamais ; seul un scenario
 * totalement absent est active automatiquement.
 */
return new class extends Migration
{
    private const VERSION = 1;

    private const PROMPT_FR = 'Tu es un assistant utile intégré à une Boucle BouclePro, un espace de discussion privé '
        .'partagé par les membres d\'une même organisation. Produis une synthèse concise et '
        .'structurée de la conversation fournie en contexte, afin que les membres retrouvent '
        .'rapidement le sens et les décisions de la Boucle. Mets en avant : les points clés '
        .'abordés, les décisions prises, les questions ouvertes et, s\'il y en a, les prochaines '
        .'étapes. Règles : réponds en français ; reste fidèle au contexte et n\'invente jamais de '
        .'faits absents ; sois concis (quelques paragraphes courts ou listes à puces) ; utilise un '
        .'Markdown léger uniquement quand il améliore réellement la lisibilité : sous-titres ## ou '
        .'### (jamais un seul #), listes à puces ou numérotées, gras et italique, mais n\'encadre '
        .'jamais toute ta réponse dans un seul bloc de code ; n\'utilise jamais de HTML brut, de '
        .'script ou de PHP ; n\'utilise que des URL http:// ou https:// ; ne révèle aucune '
        .'information interne ou sensible.';

    private const PROMPT_EN = 'You are a helpful assistant inside a BouclePro loop, a private discussion space '
        .'shared by members of the same organization. Produce a concise, structured summary '
        .'of the conversation provided as context, so members quickly recover the meaning and '
        .'decisions of the Loop. Focus on: key points discussed, decisions made, open questions, '
        .'and next steps if any. Rules: answer in English; be faithful to the context and never '
        .'invent facts that are not present; keep it concise (a few short paragraphs or bullet '
        .'lists); use light Markdown only when it genuinely helps readability: ## or ### '
        .'sub-headings (never a single #), bullet or numbered lists, bold and italic, but never '
        .'wrap your whole answer in one code block; never use raw HTML, scripts or PHP; only use '
        .'http:// or https:// URLs; never reveal any internal or sensitive information.';

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->provision(
                'chatloop_ai_summarize_fr',
                'Synthèse de Boucle (ChatLoop) — FR — v1',
                'Prompt de la capability loop_summary (FR). Copie immuable du comportement par défaut historique.',
                self::PROMPT_FR,
            );

            $this->provision(
                'chatloop_ai_summarize_en',
                'Loop summary (ChatLoop) — EN — v1',
                'Prompt of the loop_summary capability (EN). Immutable copy of the historical default behaviour.',
                self::PROMPT_EN,
            );
        });
    }

    private function provision(string $scenarioId, string $name, string $description, string $prompt): void
    {
        $scenarioExists = DB::table('admin_ai_prompts')
            ->where('scenario_id', $scenarioId)
            ->exists();

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
        // regle que TASK-1211/TASK-1213). Toute correction est forward-only.
    }
};
