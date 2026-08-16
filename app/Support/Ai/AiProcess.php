<?php

namespace App\Support\Ai;

/**
 * Identifiant technique STABLE du process IA (TASK-1131 / IA P1-1).
 *
 * `process` répond à « quel traitement BouclePro a produit cette trace ? ».
 * C'est un identifiant technique : jamais un texte traduit, jamais un libellé
 * d'interface, jamais dépendant de la locale de l'utilisateur.
 *
 * Les valeurs sont cartographiées à partir des concepts DÉJÀ présents :
 * - `ai_interactions.feature`        → self::fromFeature()
 * - `admin_ai_interactions.scenario_id` → self::fromScenarioId()
 * - type d'usage member profile      → constantes MEMBER_PROFILE_*
 *
 * Ce n'est PAS le CapabilityRegistry de P3 : aucune capacité, aucun routage,
 * aucune politique. Juste une normalisation stable pour l'observabilité.
 */
final class AiProcess
{
    public const UNKNOWN = 'unknown';

    /**
     * Écritures member profile qui n'ont ni `feature` ni `scenario_id` propre.
     */
    public const MEMBER_PROFILE_INLINE_PRESENTATION = 'member_profile.inline_presentation';

    public const MEMBER_PROFILE_LOOP_AGENT_REPLY = 'member_profile.loop_agent_reply';

    /**
     * Écritures `admin_ai_interactions` pour les invocations Laravel AI SDK
     * (TASK-1200 / IA P1-3). Seul usage réel du SDK à ce jour : les
     * embeddings Dossiers. Ces `scenario_id` sont ceux posés par les call
     * sites (`DossierArticleIndexer`, `DossierSemanticSearchService`).
     */
    public const DOSSIER_EMBEDDINGS_INDEX = 'dossier.embeddings_index';

    public const DOSSIER_EMBEDDINGS_SEARCH = 'dossier.embeddings_search';

    /**
     * `ai_interactions.feature` → process (correspondance exacte).
     *
     * @var array<string, string>
     */
    private const FEATURE_MAP = [
        'blog_generate' => 'blog.article_generate',
        'blog_correct' => 'blog.article_correct',
        'blog_explorer' => 'blog.explorer_dialogue',
        'blog_explorer_note' => 'blog.explorer_note',
        'chatloop_ai_answer' => 'chatloop.answer',
        'chatloop_ai_ask' => 'chatloop.ask',
        'chatloop_ai_summarize' => 'chatloop.summarize',
        'loop_knowledge_answer' => 'loop_knowledge.answer',
    ];

    /**
     * `feature` dynamiques : le scénario porte la méthode et/ou la locale.
     * Le préfixe le plus long gagne. Garantit qu'un même traitement produit le
     * même `process` en `fr` et en `en`.
     *
     * @var array<string, string>
     */
    private const FEATURE_PREFIX_MAP = [
        'blog_method_selection_' => 'blog.method_selection',
        'blog_explorer_dialogue_' => 'blog.explorer_dialogue',
        'blog_explorer_note_' => 'blog.explorer_note',
        'chatloop_ai_answer_' => 'chatloop.answer',
        'chatloop_ai_ask_' => 'chatloop.ask',
        'chatloop_ai_summarize_' => 'chatloop.summarize',
    ];

    /**
     * `admin_ai_interactions.scenario_id` → process.
     *
     * @var array<string, string>
     */
    private const SCENARIO_MAP = [
        'supervision_content' => 'supervision.content',
        'clarify_help_request' => 'help_request.clarify',
        'loop_knowledge_answer' => 'loop_knowledge.answer',
        'service_offer_master' => 'service_offer.master',
        'bounded_member_presentation' => 'member_profile.bounded_presentation',
        'inline_member_presentation' => self::MEMBER_PROFILE_INLINE_PRESENTATION,
        'member_ai_profile_llm_test' => 'member_profile.admin_llm_test',
        'profile_agent_setup' => 'member_profile.agent_setup',
        'profile_agent_visitor_chat' => 'member_profile.agent_visitor_chat',
        'profile_agent_master' => 'member_profile.agent_master',
        'dossier_embeddings_index' => self::DOSSIER_EMBEDDINGS_INDEX,
        'dossier_embeddings_search' => self::DOSSIER_EMBEDDINGS_SEARCH,
    ];

    private function __construct() {}

    /**
     * Process d'une écriture `ai_interactions`, à partir de son `feature`.
     */
    public static function fromFeature(?string $feature): string
    {
        return self::resolve($feature, self::FEATURE_MAP, self::FEATURE_PREFIX_MAP);
    }

    /**
     * Process d'une écriture `admin_ai_interactions`, à partir de son
     * `scenario_id`.
     */
    public static function fromScenarioId(?string $scenarioId): string
    {
        return self::resolve($scenarioId, self::SCENARIO_MAP, []);
    }

    /**
     * @param  array<string, string>  $exactMap
     * @param  array<string, string>  $prefixMap
     */
    private static function resolve(?string $raw, array $exactMap, array $prefixMap): string
    {
        $key = strtolower(trim((string) $raw));

        if ($key === '') {
            return self::UNKNOWN;
        }

        if (isset($exactMap[$key])) {
            return $exactMap[$key];
        }

        $bestPrefix = null;

        foreach ($prefixMap as $prefix => $process) {
            if (! str_starts_with($key, $prefix)) {
                continue;
            }

            if ($bestPrefix === null || strlen($prefix) > strlen($bestPrefix)) {
                $bestPrefix = $prefix;
            }
        }

        if ($bestPrefix !== null) {
            return $prefixMap[$bestPrefix];
        }

        return self::normalize($key);
    }

    /**
     * Repli pour une valeur non cartographiée : on garde un identifiant
     * technique stable et déterministe plutôt que d'inventer une taxonomie.
     *
     * Le suffixe de locale est retiré pour qu'un même traitement ne produise
     * pas deux `process` selon la langue de l'utilisateur.
     */
    private static function normalize(string $key): string
    {
        foreach (self::locales() as $locale) {
            if (str_ends_with($key, '_'.$locale)) {
                $key = substr($key, 0, -(strlen($locale) + 1));
                break;
            }
        }

        $key = preg_replace('/[^a-z0-9_.]+/', '_', $key) ?? '';
        $key = trim($key, '_.');

        if ($key === '') {
            return self::UNKNOWN;
        }

        return substr($key, 0, 100);
    }

    /**
     * @return array<int, string>
     */
    private static function locales(): array
    {
        $locales = config('services.supported_locales', ['fr', 'en']);

        return is_array($locales) && $locales !== [] ? array_values($locales) : ['fr', 'en'];
    }
}
