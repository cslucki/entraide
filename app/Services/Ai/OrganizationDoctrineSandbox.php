<?php

namespace App\Services\Ai;

use App\Ai\Agents\HelpRequestClarifierAgent;
use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\CapabilityDefinition;
use App\Ai\CapabilityRegistry;
use App\Ai\Constitution;
use App\Ai\Context\ContextBuilder;
use App\Ai\ContexteIa;
use App\Ai\PromptRepository;
use App\Ai\ProviderResolver;
use App\Ai\ResolvedModel;
use App\Models\AdminAiPrompt;
use App\Models\AiConfig;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\Organization;
use App\Models\OrganizationAiDoctrine;
use App\Models\User;
use App\Services\Ai\DTO\DoctrineSandboxResult;
use App\Support\Ai\AiCorrelation;
use App\Support\Ai\AiCost;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Ai\AiMarkdownSanitizer;
use App\Support\Ai\AiUsage;
use DomainException;
use InvalidArgumentException;

/**
 * Bac a sable « tester sans publier » de la doctrine (TASK-1227).
 *
 * « Sans publier » : la doctrine candidate n'est PAS activee, aucune action
 * metier n'est creee, rien n'est visible des autres membres.
 * « Sans publier » ne veut PAS dire « sans appel IA reel » : le test emprunte
 * le chemin canonique (registre -> ContexteIa -> ContextBuilder ->
 * ProviderResolver -> garde economique -> PromptRepository -> agent SDK) avec
 * le credential de l'Organization, et il est comptabilise au ledger comme
 * n'importe quelle invocation. Un faux modele ne prouverait rien a un Admin.
 *
 * Refus AVANT l'appel (aucun ledger, message honnete) : capability non
 * testable ici, fonction desactivee, Organization sans configuration/credential,
 * budget atteint. Jamais de repli sur une cle plateforme.
 *
 * Ce n'est PAS un second mecanisme de prompt : la doctrine candidate passe par
 * `PromptRepository::composeWithDoctrine()`, sous la meme Constitution.
 */
final class OrganizationDoctrineSandbox
{
    public const FEATURE = 'ai_doctrine_sandbox';

    public const REASON_UNSUPPORTED_CAPABILITY = 'unsupported_capability';

    public const REASON_FEATURE_DISABLED = 'feature_disabled';

    public const REASON_NOT_CONFIGURED = 'not_configured';

    public const REASON_BUDGET_REACHED = 'budget_reached';

    public const REASON_UNAVAILABLE = 'temporarily_unavailable';

    public const REASON_PROMPT_MISSING = 'prompt_missing';

    /**
     * Capabilities testables depuis l'ecran Admin : celles qui acceptent la
     * portee Organization sans Boucle. `loop_summary` exige une Boucle et
     * ses messages : hors bac a sable.
     */
    public const SUPPORTED = [
        CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER,
        CapabilityRegistry::CLARIFY_HELP_REQUEST,
    ];

    public function __construct(
        private readonly CapabilityRegistry $capabilities,
        private readonly PromptRepository $prompts,
        private readonly ProviderResolver $providers,
        private readonly ContextBuilder $contextBuilder,
        private readonly AiEconomicGuard $economicGuard,
        private readonly AiProviderInvocationLedger $ledger,
    ) {}

    /**
     * @param  string  $draftBody  la doctrine candidate (texte du formulaire, non enregistre) ; vide = sans doctrine
     */
    public function run(
        Organization $organization,
        User $admin,
        string $capability,
        string $draftBody,
        string $question,
    ): DoctrineSandboxResult {
        $question = trim($question);

        if ($question === '') {
            throw new InvalidArgumentException('A sandbox test requires a question.');
        }

        $scope = CapabilityRegistry::SCOPE_ORGANIZATION;
        $draft = OrganizationAiDoctrine::normalize($draftBody);
        $doctrineLabel = $draft === '' ? null : 'draft';

        if (! in_array($capability, self::SUPPORTED, true)) {
            return $this->refused($organization, $capability, $scope, $doctrineLabel, self::REASON_UNSUPPORTED_CAPABILITY);
        }

        $definition = $this->capabilities->get($capability);
        $this->capabilities->assertScopeAllowed($capability, $scope);

        // Memes coupe-circuits que le chemin canonique : une fonction
        // desactivee sur la plateforme ne s'appelle pas depuis le bac a sable.
        if ($capability === CapabilityRegistry::CLARIFY_HELP_REQUEST
            && (! config('ai.clarify.enabled', false) || ! AiConfig::get('clarification_enabled', false))) {
            // Les deux verrous que rencontre un membre (config plateforme ET
            // drapeau administrable) : le bac a sable ne teste jamais une
            // fonction que les membres n'ont pas.
            return $this->refused($organization, $capability, $scope, $doctrineLabel, self::REASON_FEATURE_DISABLED);
        }

        $contexte = new ContexteIa(
            organizationId: (string) $organization->id,
            userId: (string) $admin->id,
            loopId: null,
            locale: str_starts_with((string) app()->getLocale(), 'en') ? 'en' : 'fr',
            capability: $capability,
            correlationId: AiCorrelation::id(),
            source: self::FEATURE,
            query: $capability === CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER ? $question : null,
        );

        // Credential de l'Organization, ou refus explicite — jamais la plateforme.
        try {
            $resolved = $this->providers->resolve($capability, $contexte);
        } catch (DomainException) {
            return $this->refused($organization, $capability, $scope, $doctrineLabel, self::REASON_NOT_CONFIGURED);
        }

        [$budget, $unknownLimit] = $this->economicLimits($capability);
        $verdict = $this->economicGuard->authorize(
            $organization, $definition->process, $resolved->provider, $resolved->model, $budget, $unknownLimit,
        );

        if (! $verdict->allowed) {
            $reason = in_array($verdict->reason, [
                AiEconomicGuard::REASON_MONTHLY_BUDGET_REACHED,
                AiEconomicGuard::REASON_ORGANIZATION_BUDGET_REACHED,
            ], true) ? self::REASON_BUDGET_REACHED : self::REASON_UNAVAILABLE;

            return $this->refused($organization, $capability, $scope, $doctrineLabel, $reason);
        }

        $baseInstructions = $this->activeInstructions($definition);

        if ($baseInstructions === null) {
            return $this->refused($organization, $capability, $scope, $doctrineLabel, self::REASON_PROMPT_MISSING);
        }

        // La doctrine CANDIDATE, sous la Constitution, jamais lue en base.
        $instructions = $this->prompts->composeWithDoctrine($capability, $baseInstructions, $draft, null);

        // Le contexte reel : memes sources autorisees, meme tenant, memes
        // permissions que pour un membre. La doctrine n'y change rien.
        $borne = $this->contextBuilder->build($contexte, $definition);
        $sourcesCount = count($borne->provenance);

        if ($capability === CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER && $borne->provenance === []) {
            // Aucune generation — mais la recherche documentaire a pu emettre
            // une requete d'embedding REELLE (ledger `embedding/query`) : on
            // le dit tel quel, on n'annonce jamais « rien de comptabilise »
            // sur la foi de l'absence de generation (revue PASS B).
            $entries = $this->ledgerEntries($contexte);

            return new DoctrineSandboxResult(
                status: DoctrineSandboxResult::STATUS_NO_SOURCES,
                organizationId: (string) $organization->id,
                capability: $capability,
                scope: $scope,
                constitutionVersion: Constitution::VERSION,
                doctrineLabel: $doctrineLabel,
                sourcesUsed: $borne->sourcesUsed,
                sourcesDenied: $borne->sourcesDenied,
                sourcesCount: 0,
                answer: null,
                refusalReason: null,
                ledgered: $entries > 0,
                ledgerEntries: $entries,
                interactionId: null,
            );
        }

        $prompt = $capability === CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER
            ? $borne->text."\n\nQuestion du membre :\n".$question
            : ($borne->text === '' ? '' : $borne->text."\n\n")."Intention du membre :\n".$question;

        $startedAt = microtime(true);

        try {
            [$answer, $usage, $invocationId] = $capability === CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER
                ? $this->promptKnowledge($instructions, $prompt, $resolved)
                : $this->promptClarifier($instructions, $prompt, $resolved);
        } catch (\Throwable $exception) {
            $this->record($organization, $admin, $contexte, $definition, $resolved, $prompt, null,
                AiUsage::notObserved(), null, 'failed', $startedAt, null, $exception::class, $doctrineLabel);

            return new DoctrineSandboxResult(
                status: DoctrineSandboxResult::STATUS_FAILED,
                organizationId: (string) $organization->id,
                capability: $capability,
                scope: $scope,
                constitutionVersion: Constitution::VERSION,
                doctrineLabel: $doctrineLabel,
                sourcesUsed: $borne->sourcesUsed,
                sourcesDenied: $borne->sourcesDenied,
                sourcesCount: $sourcesCount,
                answer: null,
                refusalReason: null,
                ledgered: true,
                ledgerEntries: $this->ledgerEntries($contexte),
                interactionId: null,
            );
        }

        $cost = $this->economicGuard->finalize($resolved->provider, $resolved->model, $usage);

        $interaction = $this->record($organization, $admin, $contexte, $definition, $resolved, $prompt, $answer,
            $usage, $cost, 'success', $startedAt, $invocationId, null, $doctrineLabel);

        return new DoctrineSandboxResult(
            status: DoctrineSandboxResult::STATUS_ANSWERED,
            organizationId: (string) $organization->id,
            capability: $capability,
            scope: $scope,
            constitutionVersion: Constitution::VERSION,
            doctrineLabel: $doctrineLabel,
            sourcesUsed: $borne->sourcesUsed,
            sourcesDenied: $borne->sourcesDenied,
            sourcesCount: $sourcesCount,
            answer: $answer,
            refusalReason: null,
            ledgered: true,
            ledgerEntries: $this->ledgerEntries($contexte),
            interactionId: $interaction->id,
        );
    }

    /**
     * Lignes du ledger canonique portees par la correlation de CE test :
     * generation et requete d'embedding confondues. C'est ce que l'ecran
     * annonce comme comptabilise — jamais une deduction.
     */
    private function ledgerEntries(ContexteIa $contexte): int
    {
        return AiProviderInvocation::query()
            ->where('organization_id', $contexte->organizationId)
            ->where('correlation_id', $contexte->correlationId)
            ->count();
    }

    /**
     * @return array{0: string, 1: AiUsage, 2: ?string}
     */
    private function promptKnowledge(string $instructions, string $prompt, ResolvedModel $resolved): array
    {
        $agent = new LoopKnowledgeAgent(
            $instructions,
            (int) config('ai.knowledge.max_tokens', 700),
            (float) config('ai.knowledge.temperature', 0.2),
        );

        $response = $agent->prompt($prompt, provider: $resolved->instance, model: $resolved->model);

        $answer = AiMarkdownSanitizer::sanitize(
            (string) $response->text,
            (int) config('ai.knowledge.max_answer_chars', 3000),
        );

        return [
            $answer,
            AiUsage::fromSdkTextTokens($response->usage->promptTokens, $response->usage->completionTokens),
            $response->invocationId,
        ];
    }

    /**
     * @return array{0: string, 1: AiUsage, 2: ?string}
     */
    private function promptClarifier(string $instructions, string $prompt, ResolvedModel $resolved): array
    {
        $agent = new HelpRequestClarifierAgent(
            $instructions,
            (int) config('ai.clarify.max_tokens', 900),
            (float) config('ai.clarify.temperature', 0.3),
        );

        $response = $agent->prompt($prompt, provider: $resolved->instance, model: $resolved->model);
        $structured = is_array($response->structured ?? null) ? $response->structured : [];

        // Restitution lisible de la sortie structuree : titre, demande
        // clarifiee, questions. Aucun identifiant suggere n'est restitue —
        // il n'a aucune autorite hors du service canonique qui le revalide.
        $lines = array_filter([
            trim((string) ($structured['title'] ?? '')),
            trim((string) ($structured['clarified_request'] ?? '')),
            ...array_map(
                static fn ($q): string => '- '.trim((string) $q),
                is_array($structured['questions_for_user'] ?? null) ? $structured['questions_for_user'] : [],
            ),
        ], static fn (string $line): bool => $line !== '' && $line !== '- ');

        $answer = AiMarkdownSanitizer::sanitize(implode("\n\n", $lines), 3000);

        return [
            $answer,
            AiUsage::fromSdkTextTokens($response->usage->promptTokens, $response->usage->completionTokens),
            $response->invocationId,
        ];
    }

    /**
     * Instruction administrable de la capability : meme regle que les services
     * canoniques (prompt actif, derniere version), son absence est un refus.
     */
    private function activeInstructions(CapabilityDefinition $definition): ?string
    {
        $prompt = AdminAiPrompt::query()
            ->where('scenario_id', $definition->promptKey)
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();

        if ($prompt === null || trim((string) $prompt->prompt_text) === '') {
            return null;
        }

        return (string) $prompt->prompt_text;
    }

    /**
     * @return array{0: float, 1: int}
     */
    private function economicLimits(string $capability): array
    {
        return $capability === CapabilityRegistry::CLARIFY_HELP_REQUEST
            ? [
                (float) config('ai.clarify.economic_guard.monthly_budget_usd', 2.00),
                (int) config('ai.clarify.economic_guard.monthly_unknown_limit', 10),
            ]
            : [
                (float) config('ai.knowledge.economic_guard.monthly_budget_usd', 2.00),
                (int) config('ai.knowledge.economic_guard.monthly_unknown_limit', 10),
            ];
    }

    private function refused(Organization $organization, string $capability, string $scope, ?string $doctrineLabel, string $reason): DoctrineSandboxResult
    {
        return new DoctrineSandboxResult(
            status: DoctrineSandboxResult::STATUS_REFUSED,
            organizationId: (string) $organization->id,
            capability: $capability,
            scope: $scope,
            constitutionVersion: Constitution::VERSION,
            doctrineLabel: $doctrineLabel,
            sourcesUsed: [],
            sourcesDenied: [],
            sourcesCount: 0,
            answer: null,
            refusalReason: $reason,
            ledgered: false,
            ledgerEntries: 0,
            interactionId: null,
        );
    }

    /**
     * Une tentative provider reelle = une ligne du ledger canonique
     * (TASK-1220) + une trace P1 lue par la garde economique. Le bac a sable
     * est comptabilise comme n'importe quelle invocation de la capability
     * (meme `process`), et se reconnait a `feature`/`metadata.sandbox`.
     */
    private function record(
        Organization $organization,
        User $admin,
        ContexteIa $contexte,
        CapabilityDefinition $definition,
        ResolvedModel $resolved,
        string $prompt,
        ?string $response,
        AiUsage $usage,
        ?AiCost $cost,
        string $status,
        float $startedAt,
        ?string $sdkInvocationId,
        ?string $failure,
        ?string $doctrineLabel,
    ): AiInteraction {
        $this->ledger->recordGeneration(
            organizationId: (string) $organization->id,
            userId: (string) $admin->id,
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

        return AiInteraction::create([
            'user_id' => $admin->id,
            'organization_id' => $organization->id,
            'correlation_id' => $contexte->correlationId,
            'process' => $definition->process,
            'feature' => self::FEATURE,
            'model' => $resolved->trace(),
            'prompt' => $prompt,
            'response' => $response,
            'input_tokens' => $usage->inputTokensOrZero(),
            'output_tokens' => $usage->outputTokensOrZero(),
            ...($cost?->traceAttributes() ?? ['cost_usd' => null, 'cost_unknown' => null]),
            'metadata' => array_filter([
                'sandbox' => true,
                'doctrine' => $doctrineLabel ?? 'none',
                'requested_by' => $admin->id,
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'provider' => $resolved->provider,
                'capability' => $definition->id,
                'status' => $status,
                'sdk_invocation_id' => $sdkInvocationId,
                'failure' => $failure,
            ], static fn ($value): bool => $value !== null),
        ]);
    }
}
