<?php

namespace App\Services\Ai;

use App\Ai\Agents\HelpRequestClarifierAgent;
use App\Ai\CapabilityDefinition;
use App\Ai\CapabilityRegistry;
use App\Ai\Context\ContextBuilder;
use App\Ai\Context\OrganizationCategoriesSource;
use App\Ai\Context\UserLoopsSource;
use App\Ai\ContexteIa;
use App\Ai\PromptRepository;
use App\Ai\ProviderResolver;
use App\Ai\ResolvedModel;
use App\Models\AdminAiPrompt;
use App\Models\AiConfig;
use App\Models\AiInteraction;
use App\Models\Category;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTO\AssistedInteractionLabResult;
use App\Support\Ai\AiCorrelation;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Ai\AiUsage;
use DomainException;
use Illuminate\Support\Facades\Schema;

class ClarifyUserHelpRequestService implements AiProvider
{
    public function __construct(
        private readonly SupervisionProviderResolver $resolver,
        private readonly AiScenarioFactory $scenarioFactory,
        private readonly FakeAIProvider $fallback,
        private readonly CapabilityRegistry $capabilities,
        private readonly PromptRepository $prompts,
        private readonly ProviderResolver $providers,
        private readonly ContextBuilder $contextBuilder,
        private readonly AiEconomicGuard $economicGuard,
    ) {}

    public function analyze(string $phrase): AssistedInteractionLabResult
    {
        if (! config('ai.clarify.enabled', false)) {
            return $this->fallback->analyze($phrase);
        }

        $scenario = $this->scenarioFactory->resolve('clarify_help_request');

        if (! $scenario) {
            return $this->fallback->analyze($phrase);
        }

        $providerName = $this->resolver->defaultProvider();

        if (! $providerName) {
            return $this->fallback->analyze($phrase);
        }

        $model = null;
        if (Schema::hasTable('ai_configs')) {
            $model = AiConfig::get('default_model');
        }
        $model ??= config('ai.default_model')
            ?? match ($providerName) {
                'openrouter' => config('ai.openrouter.model'),
                'ollama' => config('ai.ollama.model'),
                default => config('ai.openai.model'),
            };

        if (! $model) {
            return $this->fallback->analyze($phrase);
        }

        $provider = $this->resolver->resolve($providerName);

        $result = $provider->runScenario($scenario, $phrase, $model);

        return $this->mapToDto($result, $phrase);
    }

    /**
     * Chemin transverse P3 (TASK-1210).
     *
     * Meme question que `analyze()`, mais posee dans un contexte : cet
     * utilisateur, cette Organization, ces Boucles. C'est ce qui permet a l'IA
     * de proposer un cercle au lieu d'un simple texte reformule.
     *
     * L'IA ne publie rien. Elle propose une demande et, eventuellement, une
     * Boucle — dont l'identifiant est **revalide ici** contre la liste
     * reellement fournie au contexte.
     */
    public function clarifyForLoop(Loop $loop, User $requester, string $phrase): AssistedInteractionLabResult
    {
        return $this->clarifyInContext($loop->organization, $requester, $phrase, $loop);
    }

    /**
     * Meme capability depuis le formulaire canonique d'une Demande.
     *
     * La portee reste l'Organization. Les categories de ce tenant et les
     * Boucles de l'utilisateur sont les seules destinations suggerables.
     */
    public function clarifyForOrganization(
        Organization $organization,
        User $requester,
        string $phrase,
    ): AssistedInteractionLabResult {
        if ($requester->organization_id !== $organization->id) {
            throw new DomainException('The requester does not belong to this Organization.');
        }

        return $this->clarifyInContext($organization, $requester, $phrase, null);
    }

    private function clarifyInContext(
        Organization $organization,
        User $requester,
        string $phrase,
        ?Loop $loop,
    ): AssistedInteractionLabResult {
        // Meme coupe-circuit que `analyze()` : quand la clarification IA est
        // desactivee, aucun appel provider n'est tente — et la clarification
        // deterministe prend le relais. Sans ce garde, les tests et les
        // environnements sans cle partaient en timeout reseau avant de retomber
        // sur le meme repli.
        if (! config('ai.clarify.enabled', false)) {
            return $this->fallback->analyze($phrase);
        }

        $capability = CapabilityRegistry::CLARIFY_HELP_REQUEST;
        $definition = $this->capabilities->get($capability);
        $scope = $loop === null
            ? CapabilityRegistry::SCOPE_ORGANIZATION
            : CapabilityRegistry::SCOPE_LOOP;
        $this->capabilities->assertScopeAllowed($capability, $scope);

        $contexte = new ContexteIa(
            organizationId: (string) $organization->id,
            userId: (string) $requester->id,
            loopId: $loop?->id,
            locale: str_starts_with((string) app()->getLocale(), 'en') ? 'en' : 'fr',
            capability: $capability,
            correlationId: AiCorrelation::id(),
            source: CapabilityRegistry::SOURCE_USER_LOOPS,
        );

        $borne = $this->contextBuilder->build($contexte, $definition);

        // Les Boucles reellement offertes au modele. Rien d'autre ne pourra
        // etre retenu comme suggestion.
        $loopsOffertes = array_column($borne->provenanceFor(UserLoopsSource::NAME), 'id');
        $categoriesOffertes = array_column(
            $borne->provenanceFor(OrganizationCategoriesSource::NAME),
            'id',
        );

        // TASK-1212 : provider, modele et credential viennent de l'Organization.
        // Sans configuration tenant, aucun appel : le repli deterministe prend
        // le relais, exactement comme quand la clarification IA est desactivee.
        try {
            $resolved = $this->providers->resolve($capability, $contexte);
        } catch (DomainException) {
            return $this->fallback->analyze($phrase);
        }

        // Budget mensuel de l'Organization et de la capability : un refus est
        // un refus, aucun appel SDK n'est emis.
        $verdict = $this->economicGuard->authorize(
            $organization,
            $definition->process,
            $resolved->provider,
            $resolved->model,
            (float) config('ai.clarify.economic_guard.monthly_budget_usd', 2.00),
            (int) config('ai.clarify.economic_guard.monthly_unknown_limit', 10),
        );

        if (! $verdict->allowed) {
            return $this->fallback->analyze($phrase);
        }

        $instructions = $this->prompts->compose($capability, $this->clarifyInstructions());

        $agent = new HelpRequestClarifierAgent(
            $instructions,
            (int) config('ai.clarify.max_tokens', 900),
            (float) config('ai.clarify.temperature', 0.3),
        );

        $startedAt = microtime(true);

        try {
            $response = $agent->prompt(
                $this->userPrompt($phrase, $borne->text),
                provider: $resolved->instance,
                model: $resolved->model,
            );
        } catch (\Throwable $exception) {
            $this->recordInteraction(
                $loop, $requester, $contexte, $definition, $resolved, $phrase,
                null, AiUsage::notObserved(), ['cost_usd' => null, 'cost_unknown' => null],
                'failed', $startedAt, null, $exception::class,
            );

            // Un echec ne bloque pas le membre : il retombe sur la clarification
            // deterministe, exactement comme quand l'IA est desactivee.
            return $this->fallback->analyze($phrase);
        }

        $structured = is_array($response->structured ?? null) ? $response->structured : [];

        $usage = AiUsage::fromSdkTextTokens(
            $response->usage->promptTokens,
            $response->usage->completionTokens,
        );

        $cost = $this->economicGuard->finalize($resolved->provider, $resolved->model, $usage);

        $interaction = $this->recordInteraction(
            $loop, $requester, $contexte, $definition, $resolved, $phrase,
            json_encode($structured, JSON_UNESCAPED_UNICODE), $usage, $cost->traceAttributes(),
            'success', $startedAt, $response->invocationId, null,
        );

        return $this->mapStructuredToDto(
            $structured,
            $phrase,
            $loopsOffertes,
            $categoriesOffertes,
            $organization,
            $interaction,
        );
    }

    /**
     * Revalidation serveur de la Boucle suggeree.
     *
     * Le modele peut renvoyer n'importe quelle chaine : un identifiant
     * plausible mais inexistant, celui d'une Boucle dont l'utilisateur n'est pas
     * membre, ou celui d'une autre Organization. Aucune de ces valeurs ne doit
     * survivre. Seule une correspondance EXACTE avec la liste effectivement
     * fournie au contexte est retenue ; tout le reste devient `null`, qui est un
     * resultat parfaitement valide.
     *
     * @param  list<string>  $loopsOffertes
     */
    private function validatedLoopSuggestion(array $structured, array $loopsOffertes): ?array
    {
        $suggested = trim((string) ($structured['suggested_loop_id'] ?? ''));

        if ($suggested === '' || ! in_array($suggested, $loopsOffertes, true)) {
            return null;
        }

        $loop = Loop::query()->find($suggested);

        if ($loop === null) {
            return null;
        }

        $reason = trim((string) ($structured['suggestion_reason'] ?? ''));

        return [
            'id' => $loop->id,
            'label' => $loop->name,
            'reason' => $reason !== '' ? $reason : null,
        ];
    }

    /**
     * @param  list<string>  $categoriesOffertes
     */
    private function validatedCategorySuggestion(
        array $structured,
        array $categoriesOffertes,
        Organization $organization,
    ): ?array {
        $suggested = trim((string) ($structured['suggested_category_id'] ?? ''));

        if ($suggested === '' || ! in_array($suggested, $categoriesOffertes, true)) {
            return null;
        }

        $category = Category::query()
            ->whereKey($suggested)
            ->where('organization_id', $organization->id)
            ->first();

        if ($category === null) {
            return null;
        }

        $label = $organization->transactions_naming === 'b2b'
            ? $category->name_b2b
            : $category->name_b2c;

        return ['id' => $category->id, 'label' => $label];
    }

    /**
     * @param  list<string>  $loopsOffertes
     * @param  list<string>  $categoriesOffertes
     */
    private function mapStructuredToDto(
        array $structured,
        string $originalPhrase,
        array $loopsOffertes,
        array $categoriesOffertes,
        Organization $organization,
        ?AiInteraction $interaction,
    ): AssistedInteractionLabResult {
        $confidence = (float) ($structured['confidence'] ?? 0.0);
        $needsHumanReview = (bool) ($structured['needs_human_review'] ?? true);
        $questions = is_array($structured['questions_for_user'] ?? null) ? $structured['questions_for_user'] : [];

        $fallbackNeeded = $needsHumanReview || $confidence < 0.65 || $questions !== [];

        $reason = null;

        if ($fallbackNeeded) {
            $reason = $needsHumanReview
                ? 'La demande nécessite une relecture humaine avant publication.'
                : ($questions !== []
                    ? 'Des questions de clarification sont nécessaires pour préciser la demande.'
                    : 'Confiance insuffisante pour générer un brouillon publiable.');
        }

        $helpType = (string) ($structured['help_type'] ?? 'other');
        $clarified = trim((string) ($structured['clarified_request'] ?? ''));

        return new AssistedInteractionLabResult(
            intent: $helpType === 'service_offer' ? 'offer' : 'help_request',
            confidence: $confidence,
            title: trim((string) ($structured['title'] ?? '')) ?: 'Nouvelle demande',
            need: $clarified,
            context: '',
            expectedHelpType: $this->mapHelpType($helpType),
            deadline: ['has_deadline' => false, 'label' => null, 'date' => null],
            suggestedLoop: $this->validatedLoopSuggestion($structured, $loopsOffertes),
            tone: [
                'label' => 'clair et structuré',
                'rationale' => 'Généré par clarification IA',
            ],
            messageDraft: $clarified ?: null,
            fallback: [
                'needed' => $fallbackNeeded,
                'reason' => $reason,
                'questions' => array_values(array_map('strval', $questions)),
            ],
            humanValidation: [
                'required' => true,
                'primary_label' => $fallbackNeeded ? 'Modifier la demande' : 'Valider la preview',
                'secondary_label' => $fallbackNeeded ? 'Reformuler' : 'Modifier le brouillon',
            ],
            safety: [
                'contains_sensitive_data' => false,
                'needs_human_review' => $needsHumanReview,
                'blocked' => false,
            ],
            scenario: 'clarify_help_request',
            scenarioLabel: 'Clarification de demande d\'aide',
            originalPhrase: $originalPhrase,
            suggestedCategory: $this->validatedCategorySuggestion(
                $structured,
                $categoriesOffertes,
                $organization,
            ),
            producer: 'laravel_ai_sdk',
        );
    }

    private function mapToDto(array $result, string $originalPhrase = ''): AssistedInteractionLabResult
    {
        $confidence = (float) ($result['confidence'] ?? 0.0);
        $needsHumanReview = (bool) ($result['needs_human_review'] ?? true);
        $questions = $result['questions_for_user'] ?? [];

        $fallbackNeeded = $needsHumanReview || $confidence < 0.65 || ! empty($questions);

        $reason = null;

        if ($fallbackNeeded) {
            if ($needsHumanReview) {
                $reason = 'La demande nécessite une relecture humaine avant publication.';
            } elseif (! empty($questions)) {
                $reason = 'Des questions de clarification sont nécessaires pour préciser la demande.';
            } else {
                $reason = 'Confiance insuffisante pour générer un brouillon publiable.';
            }
        }

        $helpType = $result['help_type'] ?? 'other';

        return new AssistedInteractionLabResult(
            intent: $helpType === 'service_offer' ? 'offer' : 'help_request',
            confidence: $confidence,
            title: $result['title'] ?? 'Nouvelle demande',
            need: $result['clarified_request'] ?? ($result['publishable_draft'] ?? ''),
            context: '',
            expectedHelpType: $this->mapHelpType($helpType),
            deadline: ['has_deadline' => false, 'label' => null, 'date' => null],
            suggestedLoop: isset($result['suggested_loop']) && $result['suggested_loop'] !== ''
                ? ['id' => null, 'label' => $result['suggested_loop'], 'reason' => 'Suggéré par l\'analyse IA']
                : null,
            tone: [
                'label' => 'clair et structuré',
                'rationale' => 'Généré par clarification IA',
            ],
            messageDraft: $result['publishable_draft'] ?? ($result['clarified_request'] ?? null),
            fallback: [
                'needed' => $fallbackNeeded,
                'reason' => $reason,
                'questions' => $questions,
            ],
            humanValidation: [
                'required' => $fallbackNeeded,
                'primary_label' => $fallbackNeeded ? 'Modifier la demande' : 'Valider la preview',
                'secondary_label' => $fallbackNeeded ? 'Reformuler' : 'Modifier le brouillon',
            ],
            safety: [
                'contains_sensitive_data' => false,
                'needs_human_review' => $needsHumanReview,
                'blocked' => false,
            ],
            scenario: 'clarify_help_request',
            scenarioLabel: 'Clarification de demande d\'aide',
            originalPhrase: $originalPhrase,
            producer: 'legacy_provider',
        );
    }

    private function mapHelpType(string $helpType): string
    {
        return match ($helpType) {
            'service_offer' => 'proposition de service',
            'collaboration' => 'collaboration',
            'information' => 'information, conseil',
            'support' => 'soutien, accompagnement',
            default => 'autre',
        };
    }

    /**
     * Instruction capability chargee depuis la source editable. L'absence de
     * prompt actif est une indisponibilite explicite : aucun texte metier
     * hardcode ne doit contourner silencieusement l'administration.
     */
    private function clarifyInstructions(): string
    {
        $prompt = AdminAiPrompt::query()
            ->where('scenario_id', 'clarify_help_request')
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();

        if ($prompt === null || trim((string) $prompt->prompt_text) === '') {
            throw new DomainException('No active AdminAiPrompt is configured for clarify_help_request.');
        }

        return (string) $prompt->prompt_text;
    }

    private function userPrompt(string $phrase, string $contexteBorne): string
    {
        $intention = "Intention du membre :\n".trim($phrase);

        return $contexteBorne === ''
            ? $intention
            : $contexteBorne."\n\n".$intention;
    }

    /**
     * Trace P1 de la capability. Une seule ecriture par appel, au call site —
     * meme regle que `loop_summary` : aucun listener SDK texte, sans quoi le
     * meme appel serait compte deux fois.
     *
     * @param  array{cost_usd: ?float, cost_unknown: ?bool}  $costAttributes
     */
    private function recordInteraction(
        ?Loop $loop,
        User $requester,
        ContexteIa $contexte,
        CapabilityDefinition $definition,
        ResolvedModel $resolved,
        string $phrase,
        ?string $response,
        AiUsage $usage,
        array $costAttributes,
        string $status,
        float $startedAt,
        ?string $sdkInvocationId,
        ?string $failure,
    ): AiInteraction {
        return AiInteraction::create([
            'user_id' => $requester->id,
            'organization_id' => $contexte->organizationId,
            'correlation_id' => $contexte->correlationId,
            'process' => $definition->process,
            'feature' => 'clarify_help_request',
            'model' => $resolved->trace(),
            'prompt' => $phrase,
            'response' => $response,
            'input_tokens' => $usage->inputTokensOrZero(),
            'output_tokens' => $usage->outputTokensOrZero(),
            ...$costAttributes,
            'metadata' => array_filter([
                'loop_id' => $loop?->id,
                'requested_by' => $requester->id,
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
