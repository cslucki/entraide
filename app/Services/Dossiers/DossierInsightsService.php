<?php

namespace App\Services\Dossiers;

use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\CapabilityDefinition;
use App\Ai\CapabilityRegistry;
use App\Ai\Context\DossierSourceUrl;
use App\Ai\ContexteIa;
use App\Ai\PromptRepository;
use App\Ai\ProviderResolver;
use App\Ai\ResolvedModel;
use App\Models\AdminAiPrompt;
use App\Models\AiInteraction;
use App\Models\Dossier;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\AiProviderInvocationLedger;
use App\Services\Ai\DTO\KnowledgeAnswer;
use App\Support\Ai\AiCorrelation;
use App\Support\Ai\AiCost;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Ai\AiMarkdownSanitizer;
use App\Support\Ai\AiRefusedException;
use App\Support\Ai\AiUsage;
use DomainException;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * TASK-1341 — Smart Dossier V1 : « qu'est-ce qui ressort de ce Dossier, et sur
 * quels documents cela repose ? ».
 *
 * Architecture COMPRESSEE obligatoire (brief 2026-08-30) : cette classe est la
 * SEULE piece backend neuve. Elle ne cree ni capability, ni process, ni
 * Agent, ni DTO, ni migration — elle rejoue la capability
 * `loop_knowledge_answer` (meme process, meme AdminAiPrompt, meme
 * `LoopKnowledgeAgent`, meme DTO `KnowledgeAnswer`) sur une QUESTION
 * PREREGLEE, avec pour corpus le seul Dossier ouvert.
 *
 * Retrieval : `DossierSemanticSearchService::representativeChunksAcrossDossiers()`
 * (TASK-1309) — un chunk representatif par DOCUMENT, aucune recherche, aucun
 * embedding. Le Context Builder / `DossierRetrievalSource` ne sont PAS
 * reutilises ici (PREP-LIGHT 2026-08-30) : ce sont des `ContextSource` pensees
 * pour le pipeline multi-source d'un tour de chat de Boucle, un couplage
 * inutile pour un appel isole, Dossier-scope, hors Loop.
 *
 * Rien n'est persiste au-dela de la trace habituelle (ledger +
 * `AiInteraction`) : le resultat est ephemere, relu a chaque generation,
 * exactement comme la recherche semantique existante.
 */
final class DossierInsightsService
{
    /**
     * Rubriques attendues, DANS CET ORDRE. Une rubrique absente de la reponse
     * filtree est simplement omise — jamais remplie par un texte generique.
     */
    private const HEADING_SUMMARY = 'Synthèse';

    private const HEADING_FACTS = 'Faits saillants';

    private const HEADING_CONVERGENCES = 'Convergences';

    private const HEADING_ATTENTION = 'Points nécessitant attention';

    private const HEADING_QUESTIONS = 'Questions possibles';

    private const BULLET_HEADINGS = [
        self::HEADING_FACTS,
        self::HEADING_CONVERGENCES,
        self::HEADING_ATTENTION,
        self::HEADING_QUESTIONS,
    ];

    private const ALL_HEADINGS = [
        self::HEADING_SUMMARY,
        self::HEADING_FACTS,
        self::HEADING_CONVERGENCES,
        self::HEADING_ATTENTION,
        self::HEADING_QUESTIONS,
    ];

    /**
     * Nombre de documents representatifs demandes au corpus — meme borne que
     * la vue d'ensemble documentaire (TASK-1309).
     */
    private const DOCUMENT_LIMIT = 6;

    public function __construct(
        private readonly DossierSemanticSearchService $search,
        private readonly CapabilityRegistry $capabilities,
        private readonly PromptRepository $prompts,
        private readonly ProviderResolver $providers,
        private readonly AiEconomicGuard $economicGuard,
        private readonly AiProviderInvocationLedger $ledger,
    ) {}

    /**
     * Le Dossier a-t-il seulement de quoi produire un Insight ? Reutilise
     * EXACTEMENT la meme regle d'eligibilite documentaire que la generation
     * elle-meme (Article publie / fichier non supprime) — jamais une seconde
     * requete qui pourrait diverger.
     */
    public function hasIndexedContent(Organization $organization, Dossier $dossier): bool
    {
        return $this->search->representativeChunksAcrossDossiers(
            (string) $organization->id,
            [(string) $dossier->id],
            1,
        ) !== [];
    }

    public function generate(Organization $organization, Dossier $dossier, User $requester): KnowledgeAnswer
    {
        if ((string) $dossier->organization_id !== (string) $organization->id) {
            throw new RuntimeException(__('dossiers.insights_cross_organization'));
        }

        // Revalidation serveur — jamais une confiance sur « la page est deja
        // ouverte » (PREP-LIGHT §4.2).
        if (Gate::forUser($requester)->denies('view', $dossier)) {
            throw new RuntimeException(__('dossiers.insights_not_authorized'));
        }

        $capability = CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER;
        $definition = $this->capabilities->get($capability);
        // Smart Dossier tourne HORS Loop : la capability autorise deja la
        // portee Organization (PREP-LIGHT §3).
        $this->capabilities->assertScopeAllowed($capability, CapabilityRegistry::SCOPE_ORGANIZATION);

        $rows = $this->search->representativeChunksAcrossDossiers(
            (string) $organization->id,
            [(string) $dossier->id],
            self::DOCUMENT_LIMIT,
        );

        if ($rows === []) {
            throw new RuntimeException(__('dossiers.insights_no_content'));
        }

        $contexte = new ContexteIa(
            organizationId: (string) $organization->id,
            userId: (string) $requester->id,
            loopId: null,
            locale: str_starts_with((string) app()->getLocale(), 'en') ? 'en' : 'fr',
            capability: $capability,
            correlationId: AiCorrelation::id(),
            source: CapabilityRegistry::SOURCE_DOSSIER_RETRIEVAL,
            query: self::presetQuestionSummary(),
        );

        // P4 : sans configuration IA d'Organization, aucun appel, aucun repli.
        try {
            $resolved = $this->providers->resolve($capability, $contexte);
        } catch (DomainException $exception) {
            throw AiRefusedException::notConfigured($exception);
        }

        $verdict = $this->economicGuard->authorize(
            $organization,
            $definition->process,
            $resolved->provider,
            $resolved->model,
            (float) config('ai.knowledge.economic_guard.monthly_budget_usd', 2.00),
            (int) config('ai.knowledge.economic_guard.monthly_unknown_limit', 10),
            $requester,
        );

        if (! $verdict->allowed) {
            throw AiRefusedException::fromVerdict($verdict);
        }

        $instructions = $this->prompts->compose($capability, $this->capabilityInstructions($definition->promptKey), (string) $organization->id);
        $doctrineVersion = $this->prompts->activeDoctrineVersion((string) $organization->id);

        [$sourcesBlock, $consulted] = $this->buildSourcesBlock($organization, $rows);

        $agent = new LoopKnowledgeAgent(
            $instructions,
            (int) config('ai.knowledge.max_tokens', 700),
            (float) config('ai.knowledge.temperature', 0.2),
        );

        $prompt = $sourcesBlock."\n\n".self::presetQuestion();

        $startedAt = microtime(true);

        try {
            $response = $agent->prompt($prompt, provider: $resolved->instance, model: $resolved->model);
        } catch (\Throwable $exception) {
            $this->recordInteraction($dossier, $requester, $contexte, $definition, $resolved, $prompt, null,
                AiUsage::notObserved(), ['cost_usd' => null, 'cost_unknown' => null], null, 'failed', $startedAt, null,
                $exception::class, $consulted, [], $doctrineVersion);

            throw new RuntimeException(__('dossiers.insights_ai_error'), 0, $exception);
        }

        $rawAnswer = AiMarkdownSanitizer::sanitize(
            (string) $response->text,
            (int) config('ai.knowledge.max_answer_chars', 3000),
        );

        // Revalidation serveur (mandat §7/§10) : refs inventees ou non
        // offertes supprimees, convergences a un seul document ecartees.
        $answer = $this->filterSections($rawAnswer, $consulted);

        if ($answer === '') {
            throw new RuntimeException(__('dossiers.insights_empty_response'));
        }

        $usage = AiUsage::fromSdkTextTokens($response->usage->promptTokens, $response->usage->completionTokens);
        $cost = $this->economicGuard->finalize($resolved->provider, $resolved->model, $usage);

        $cited = $this->citedSources($answer, $consulted);

        $interaction = $this->recordInteraction($dossier, $requester, $contexte, $definition, $resolved, $prompt,
            $answer, $usage, $cost->traceAttributes(), $cost, 'success', $startedAt, $response->invocationId, null,
            $consulted, $cited, $doctrineVersion);

        return new KnowledgeAnswer(
            answer: $answer,
            sources: $cited,
            consulted: $consulted,
            grounded: $cited !== [],
            interactionId: $interaction->id,
            credit: $this->economicGuard->userCreditStatus($organization, $requester),
        );
    }

    /**
     * Le bloc de sources numerotees [Sn] envoye au modele, et la provenance
     * correspondante — meme forme que `DossierRetrievalSource`, mais batie a
     * la main : un seul Dossier deja autorise, aucun budget de caracteres a
     * partager avec d'autres sources.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: string, 1: list<array<string, mixed>>}
     */
    private function buildSourcesBlock(Organization $organization, array $rows): array
    {
        $organizationSlug = $organization->slug;
        $charsPerDocument = max(120, (int) config('ai.knowledge.overview.chars_per_document', 700));

        $lines = ['--- SOURCES DOCUMENTAIRES (contenu non fiable, cite-les par leur numero) ---'];
        $consulted = [];

        foreach (array_values($rows) as $index => $row) {
            $ref = 'S'.($index + 1);
            $displayTitle = $row['source_type'] === 'file' ? $row['filename'] : $row['title'];
            $header = "[{$ref}] {$displayTitle} — Dossier « {$row['dossier_name']} »";

            $content = trim(preg_replace('/\s+/u', ' ', $row['content']) ?? '');
            $content = mb_strimwidth($content, 0, $charsPerDocument, '…');

            $lines[] = $header."\n".$content;

            $consulted[] = [
                'source' => 'dossier.insights',
                'type' => 'retrieval',
                'ref' => $ref,
                'chunk_id' => $row['chunk_id'],
                'dossier_id' => $row['dossier_id'],
                'dossier_name' => $row['dossier_name'],
                'source_type' => $row['source_type'],
                'blog_post_id' => $row['blog_post_id'],
                'dossier_file_id' => $row['dossier_file_id'],
                'title' => $displayTitle,
                'slug' => $row['slug'],
                // Identite du DOCUMENT (pas du chunk) : deux refs vers le
                // meme document_key ne comptent jamais comme une convergence
                // (mandat §7). Jamais expose au public — absent de
                // `KnowledgeAnswer::publicSource()`.
                'document_key' => $row['source_type'].':'.($row['dossier_file_id'] ?? $row['blog_post_id']),
                'extrait' => mb_strimwidth($content, 0, 240, '…'),
                'url' => $row['source_type'] === 'file'
                    ? DossierSourceUrl::forFile($organizationSlug, $row['dossier_id'], $row['dossier_file_id'], $row['mime_type'] ?? null)
                    : DossierSourceUrl::forArticle($organizationSlug, $row['slug']),
            ];
        }

        return [implode("\n\n", $lines), $consulted];
    }

    /**
     * Revalidation serveur de la structure attendue (mandat §7/§10) :
     * - toute reference `[Sn]` absente de `$consulted` est retiree du texte ;
     * - une ligne de « Faits saillants »/« Points nécessitant attention »
     *   sans AUCUNE reference valide est supprimee ;
     * - une ligne de « Convergences » soutenue par MOINS de deux documents
     *   DISTINCTS (meme document cite deux fois compris) est supprimee ;
     * - une rubrique qui ne survit a aucune ligne est purement et simplement
     *   absente du rendu — jamais un titre suivi de rien.
     *
     * Si le modele ne respecte pas la structure attendue (aucune rubrique
     * reconnue), le resultat est une chaine vide : mode degrade sur, jamais
     * une convergence inventee.
     *
     * @param  list<array<string, mixed>>  $consulted
     */
    private function filterSections(string $markdown, array $consulted): string
    {
        $documentKeyByRef = [];

        foreach ($consulted as $row) {
            $documentKeyByRef[$row['ref']] = $row['document_key'];
        }

        $validRefs = array_keys($documentKeyByRef);
        $sections = $this->splitSections($markdown);
        $kept = [];

        foreach (self::ALL_HEADINGS as $heading) {
            if (! array_key_exists($heading, $sections)) {
                continue;
            }

            if (! in_array($heading, self::BULLET_HEADINGS, true)) {
                $text = trim($this->stripInventedRefs($sections[$heading], $validRefs));

                if ($text !== '') {
                    $kept[] = "## {$heading}\n\n{$text}";
                }

                continue;
            }

            $keptLines = [];

            foreach (preg_split('/\r?\n/', trim($sections[$heading])) as $line) {
                $line = trim($line);

                if ($line === '' || ! str_starts_with($line, '-')) {
                    continue;
                }

                $refsInLine = $this->extractRefs($line);
                $validRefsInLine = array_values(array_intersect($refsInLine, $validRefs));
                $line = trim($this->stripInventedRefs($line, $validRefs));

                if ($heading === self::HEADING_QUESTIONS) {
                    // Texte inerte, suggestif : aucune citation exigee.
                    if (trim($line, "- \t") !== '') {
                        $keptLines[] = $line;
                    }

                    continue;
                }

                if ($validRefsInLine === []) {
                    // Aucune reference valide : jamais rendu (mandat §10).
                    continue;
                }

                if ($heading === self::HEADING_CONVERGENCES) {
                    $documentKeys = array_unique(array_map(
                        static fn (string $ref): string => $documentKeyByRef[$ref],
                        $validRefsInLine,
                    ));

                    if (count($documentKeys) < 2) {
                        // Deux refs du meme document != une convergence.
                        continue;
                    }
                }

                $keptLines[] = $line;
            }

            if ($keptLines !== []) {
                $kept[] = "## {$heading}\n".implode("\n", $keptLines);
            }
        }

        return trim(implode("\n\n", $kept));
    }

    /**
     * @return array<string, string> rubrique attendue => corps brut
     */
    private function splitSections(string $markdown): array
    {
        if (! preg_match_all('/^##\s+(.+?)\s*$/m', $markdown, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $sections = [];
        $count = count($matches[0]);

        for ($i = 0; $i < $count; $i++) {
            $heading = trim($matches[1][$i][0]);

            if (! in_array($heading, self::ALL_HEADINGS, true)) {
                // Un titre hors contrat n'ouvre jamais une rubrique — le
                // modele ne peut pas inventer une septieme categorie.
                continue;
            }

            $start = $matches[0][$i][1] + strlen($matches[0][$i][0]);
            $end = $i + 1 < $count ? $matches[0][$i + 1][1] : strlen($markdown);

            $sections[$heading] = ($sections[$heading] ?? '')."\n".substr($markdown, $start, $end - $start);
        }

        return $sections;
    }

    /**
     * @return list<string>
     */
    private function extractRefs(string $text): array
    {
        preg_match_all('/\[(S\d+)\]/', $text, $matches);

        return $matches[1];
    }

    /**
     * @param  list<string>  $validRefs
     */
    private function stripInventedRefs(string $text, array $validRefs): string
    {
        return preg_replace_callback(
            '/\[(S\d+)\]/',
            static fn (array $match): string => in_array($match[1], $validRefs, true) ? $match[0] : '',
            $text,
        ) ?? $text;
    }

    /**
     * @param  list<array<string, mixed>>  $consulted
     * @return list<array<string, mixed>>
     */
    private function citedSources(string $answer, array $consulted): array
    {
        return array_values(array_filter(
            $consulted,
            static fn (array $source): bool => str_contains($answer, '['.$source['ref'].']'),
        ));
    }

    private function capabilityInstructions(string $scenarioId): string
    {
        $prompt = AdminAiPrompt::query()
            ->where('scenario_id', $scenarioId)
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();

        if ($prompt === null || trim((string) $prompt->prompt_text) === '') {
            throw new RuntimeException(__('dossiers.insights_prompt_missing'));
        }

        return (string) $prompt->prompt_text;
    }

    /**
     * @param  array{cost_usd: ?float, cost_unknown: ?bool}  $costAttributes
     * @param  list<array<string, mixed>>  $consulted
     * @param  list<array<string, mixed>>  $cited
     */
    private function recordInteraction(
        Dossier $dossier,
        User $requester,
        ContexteIa $contexte,
        CapabilityDefinition $definition,
        ResolvedModel $resolved,
        string $prompt,
        ?string $response,
        AiUsage $usage,
        array $costAttributes,
        ?AiCost $cost,
        string $status,
        float $startedAt,
        ?string $sdkInvocationId,
        ?string $failure,
        array $consulted,
        array $cited,
        ?int $doctrineVersion,
    ): AiInteraction {
        $this->ledger->recordGeneration(
            organizationId: $contexte->organizationId,
            userId: (string) $requester->id,
            capability: $definition->id,
            process: $definition->process,
            resolved: $resolved,
            usage: $usage,
            cost: $cost,
            status: $status,
            correlationId: $contexte->correlationId,
            sdkInvocationId: $sdkInvocationId,
            failureReason: $failure,
            startedAtMicrotime: $startedAt,
        );

        $ids = static fn (array $sources): array => array_values(array_map(
            static fn (array $s): array => ['chunk_id' => $s['chunk_id'] ?? null, 'dossier_id' => $s['dossier_id'] ?? null],
            $sources,
        ));

        return AiInteraction::create([
            'user_id' => $requester->id,
            'organization_id' => $contexte->organizationId,
            'correlation_id' => $contexte->correlationId,
            'process' => $definition->process,
            'feature' => $definition->id,
            'model' => $resolved->trace(),
            'prompt' => $prompt,
            'response' => $response,
            'input_tokens' => $usage->inputTokensOrZero(),
            'output_tokens' => $usage->outputTokensOrZero(),
            ...$costAttributes,
            'metadata' => array_filter([
                'dossier_id' => $dossier->id,
                'requested_by' => $requester->id,
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'provider' => $resolved->provider,
                'capability' => $definition->id,
                'status' => $status,
                'sdk_invocation_id' => $sdkInvocationId,
                'failure' => $failure,
                'retrieval' => ['consulted' => $ids($consulted), 'cited' => $ids($cited)],
            ], static fn ($value): bool => $value !== null)
                + ['doctrine_version' => $doctrineVersion],
        ]);
    }

    /**
     * Question preetablie de Smart Dossier V1 — jamais lue depuis une
     * requete. Fixe le contrat de structure ET la doctrine de groundedness
     * (2 documents distincts pour une convergence, aucune categorie remplie
     * par defaut) directement dans le tour, sans toucher a l'AdminAiPrompt
     * `loop_knowledge_answer` partage avec le Q&A de Boucle.
     */
    private static function presetQuestion(): string
    {
        return <<<'TEXT'
        Question du membre :
        À partir UNIQUEMENT des sources documentaires ci-dessus — un échantillon
        représentatif et borné de ce Dossier, jamais son contenu intégral —,
        produis une synthèse de ce qui en ressort, structurée EXACTEMENT ainsi,
        avec ces titres Markdown, dans cet ordre :

        ## Synthèse
        Deux à quatre phrases resituant ce que ces documents apportent.

        ## Faits saillants
        Une liste à puces (« - ... [Sn] »). Chaque ligne cite au moins une
        source réelle parmi celles fournies. N'invente jamais un fait absent
        des sources.

        ## Convergences
        Une liste à puces. N'écris une ligne QUE si au moins DEUX DOCUMENTS
        DIFFÉRENTS (pas deux extraits du même document) disent la même chose —
        cite alors les deux, par exemple « ... [S1][S4] ». Si aucune vraie
        convergence entre documents distincts n'existe, n'écris PAS cette
        rubrique du tout.

        ## Points nécessitant attention
        Une liste à puces. Uniquement des tensions, manques ou incohérences
        RÉELLEMENT visibles dans les sources, chacune citée. Jamais une
        priorité ou une urgence que les sources ne démontrent pas. Si rien ne
        le justifie, n'écris PAS cette rubrique.

        ## Questions possibles
        Une liste à puces de questions ouvertes, suggestives, qu'un lecteur
        pourrait explorer ensuite. Texte seul, jamais une action, jamais une
        citation requise.

        Règle absolue : n'invente aucune référence [Sn] en dehors de celles
        listées ci-dessus. Une rubrique sans contenu réellement fondé est
        omise entièrement — jamais remplie par une phrase générique.
        TEXT;
    }

    private static function presetQuestionSummary(): string
    {
        return 'smart_dossier_insights_v1';
    }
}
