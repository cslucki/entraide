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
     * Rubriques attendues, DANS CET ORDRE, designees par un identifiant
     * STABLE et non par leur libelle.
     *
     * Un titre de rubrique n'est pas de la chrome d'ecran : il est dicte au
     * modele dans la question preetablie, relu par le parseur de la reponse,
     * puis reemis dans le markdown rendu. Les trois usages doivent lire la
     * meme autorite. Les comparer par identifiant plutot que par texte est ce
     * qui rend cette autorite traduisible sans qu'aucune branche de code ne
     * connaisse une langue.
     */
    private const HEADING_KEYS = [
        'summary' => 'dossiers.insights_heading_summary',
        'facts' => 'dossiers.insights_heading_facts',
        'convergences' => 'dossiers.insights_heading_convergences',
        'attention' => 'dossiers.insights_heading_attention',
        'questions' => 'dossiers.insights_heading_questions',
    ];

    /**
     * Rubriques dont le corps est une liste a puces — les seules soumises au
     * filtrage ligne a ligne.
     */
    private const BULLET_SLUGS = ['facts', 'convergences', 'attention', 'questions'];

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

        // La langue du contenu SYSTEME produit pour une Organization est celle
        // de l'Organization, jamais celle du lecteur (arbitrage MASTER du
        // 04/09, deja applique par TASK-1388 et TASK-1390). Un Insight est
        // relu par tout le cercle : le faire suivre la langue de qui appuie
        // sur le bouton donnerait au meme Dossier deux langues selon le
        // visiteur.
        $locale = $this->localeDeReference($organization);

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
            locale: $locale,
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

        $prompt = $sourcesBlock."\n\n".$this->presetQuestion($locale);

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
        $answer = $this->filterSections($rawAnswer, $consulted, $locale);

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
    private function filterSections(string $markdown, array $consulted, string $locale): string
    {
        $documentKeyByRef = [];

        foreach ($consulted as $row) {
            $documentKeyByRef[$row['ref']] = $row['document_key'];
        }

        $validRefs = array_keys($documentKeyByRef);
        $sections = $this->splitSections($markdown, $locale);
        $kept = [];

        foreach (array_keys(self::HEADING_KEYS) as $slug) {
            if (! array_key_exists($slug, $sections)) {
                continue;
            }

            $heading = $this->heading($slug, $locale);

            if (! in_array($slug, self::BULLET_SLUGS, true)) {
                $text = trim($this->stripInventedRefs($sections[$slug], $validRefs));

                if ($text !== '') {
                    $kept[] = "## {$heading}\n\n{$text}";
                }

                continue;
            }

            $keptLines = [];

            foreach (preg_split('/\r?\n/', trim($sections[$slug])) as $line) {
                $line = trim($line);

                if ($line === '' || ! str_starts_with($line, '-')) {
                    continue;
                }

                $refsInLine = $this->extractRefs($line);
                $validRefsInLine = array_values(array_intersect($refsInLine, $validRefs));
                $line = trim($this->stripInventedRefs($line, $validRefs));

                if ($slug === 'questions') {
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

                if ($slug === 'convergences') {
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
     * @return array<string, string> identifiant de rubrique => corps brut
     */
    private function splitSections(string $markdown, string $locale): array
    {
        if (! preg_match_all('/^##\s+(.+?)\s*$/m', $markdown, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $slugParTitre = [];

        foreach (array_keys(self::HEADING_KEYS) as $slug) {
            $slugParTitre[$this->heading($slug, $locale)] = $slug;
        }

        $sections = [];
        $count = count($matches[0]);

        for ($i = 0; $i < $count; $i++) {
            $heading = trim($matches[1][$i][0]);

            if (! array_key_exists($heading, $slugParTitre)) {
                // Un titre hors contrat n'ouvre jamais une rubrique — le
                // modele ne peut pas inventer une septieme categorie. Et un
                // titre rendu dans une AUTRE langue que celle demandee n'en
                // ouvre pas davantage : la rubrique tombe, ce qui rend le
                // desaccord visible plutot que silencieux.
                continue;
            }

            $slug = $slugParTitre[$heading];

            $start = $matches[0][$i][1] + strlen($matches[0][$i][0]);
            $end = $i + 1 < $count ? $matches[0][$i + 1][1] : strlen($markdown);

            $sections[$slug] = ($sections[$slug] ?? '')."\n".substr($markdown, $start, $end - $start);
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
    private function presetQuestion(string $locale): string
    {
        return (string) trans('dossiers.insights_preset_question', [], $locale);
    }

    /**
     * Le libelle d'une rubrique, dans la langue de l'Organization.
     */
    private function heading(string $slug, string $locale): string
    {
        return (string) trans(self::HEADING_KEYS[$slug], [], $locale);
    }

    /**
     * La langue qui fait autorite pour le contenu produit.
     *
     * `Organization.locale` a une valeur par defaut en base (`fr`) et n'est
     * donc jamais nulle en pratique ; le repli reste ecrit pour qu'une
     * Organization arrivant d'un chemin qui ne l'a pas posee ne produise pas
     * un `trans()` sur une locale vide, ou le traducteur retomberait sur la
     * langue du LECTEUR — precisement ce que cette tranche supprime.
     */
    private function localeDeReference(Organization $organization): string
    {
        $locale = trim((string) $organization->locale);

        return $locale !== '' ? $locale : (string) config('app.fallback_locale', 'fr');
    }

    private static function presetQuestionSummary(): string
    {
        return 'smart_dossier_insights_v1';
    }
}
