<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TASK-1327 (Premium-1) : provisioning deploy-safe des prompts
 * `loop_decision_suggestion_fr` et `loop_decision_suggestion_en`
 * (capability `loop_decision_suggestion`, « Decision Memory IA »).
 *
 * Meme regle que TASK-1211/TASK-1213/TASK-1221 : le point canonique EXIGE un
 * prompt AdminAiPrompt actif, sans repli hardcode — cette migration garantit
 * qu'il existe des le deploiement. Une ligne deja presente est une donnee
 * administrable, la migration n'y retouche jamais ; seul un scenario
 * totalement absent est active automatiquement.
 *
 * Migration de DONNEES uniquement : aucun schema, aucune table.
 */
return new class extends Migration
{
    private const VERSION = 1;

    private const PROMPT_FR = 'Tu es un assistant intégré à une Boucle BouclePro, un espace de discussion privé '
        .'partagé par les membres d\'une même organisation. Ta tâche : déterminer si la conversation '
        .'fournie en contexte a abouti à une DÉCISION claire et collective, et si oui, proposer un '
        .'brouillon de capitalisation que un humain vérifiera et validera. Tu ne décides rien '
        .'toi-même : tu proposes, l\'humain tranche. '
        .'Réponds UNIQUEMENT par un objet JSON, sans texte autour, avec exactement ces clés : '
        .'"decision_found" (booléen), "title" (chaîne), "rationale" (chaîne), "source_message_id" (chaîne ou null). '
        .'Règles strictes : si la conversation ne contient PAS de décision claire (simple échange, '
        .'question ouverte, désaccord non tranché), renvoie {"decision_found": false, "title": "", '
        .'"rationale": "", "source_message_id": null} — ne force JAMAIS une suggestion. '
        .'Si une décision claire est présente : "title" est un titre court et factuel de la décision '
        .'(jamais une copie du message) ; "rationale" résume le contexte, les raisons avancées et, '
        .'seulement si elles ont été réellement évoquées dans la conversation, les alternatives '
        .'écartées — n\'invente jamais de faits, de raisons ni d\'alternatives absents du contexte ; '
        .'"source_message_id" est l\'identifiant EXACT du message qui conclut la décision, choisi '
        .'UNIQUEMENT dans la liste MESSAGES CANDIDATS fournie après le contexte — jamais un '
        .'identifiant inventé ou modifié. Les clés du JSON restent en anglais telles quelles ; '
        .'les valeurs "title" et "rationale" sont rédigées dans la langue demandée.';

    private const PROMPT_EN = 'You are an assistant inside a BouclePro loop, a private discussion space shared by '
        .'members of the same organization. Your task: determine whether the conversation provided '
        .'as context has reached a clear, collective DECISION, and if so, propose a capitalization '
        .'draft that a human will review and validate. You never decide anything yourself: you '
        .'propose, the human decides. '
        .'Answer ONLY with a JSON object, no surrounding text, with exactly these keys: '
        .'"decision_found" (boolean), "title" (string), "rationale" (string), "source_message_id" (string or null). '
        .'Strict rules: if the conversation does NOT contain a clear decision (plain exchange, open '
        .'question, unresolved disagreement), return {"decision_found": false, "title": "", '
        .'"rationale": "", "source_message_id": null} — NEVER force a suggestion. '
        .'If a clear decision is present: "title" is a short, factual title of the decision (never a '
        .'copy of the message); "rationale" summarizes the context, the reasons given and, only if '
        .'they were actually mentioned in the conversation, the discarded alternatives — never '
        .'invent facts, reasons or alternatives absent from the context; "source_message_id" is the '
        .'EXACT identifier of the message that concludes the decision, chosen ONLY from the '
        .'CANDIDATE MESSAGES list provided after the context — never an invented or altered '
        .'identifier. JSON keys stay in English as-is; the "title" and "rationale" values are '
        .'written in the requested language.';

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->provision(
                'loop_decision_suggestion_fr',
                'Suggestion de Décision (Decision Memory) — FR — v1',
                'Prompt de la capability loop_decision_suggestion (FR). L\'IA propose un brouillon de Décision, l\'humain capitalise.',
                self::PROMPT_FR,
            );

            $this->provision(
                'loop_decision_suggestion_en',
                'Decision suggestion (Decision Memory) — EN — v1',
                'Prompt of the loop_decision_suggestion capability (EN). The AI proposes a Decision draft, the human capitalizes.',
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
