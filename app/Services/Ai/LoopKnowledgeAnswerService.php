<?php

namespace App\Services\Ai;

use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\CapabilityDefinition;
use App\Ai\CapabilityRegistry;
use App\Ai\Context\ContextBuilder;
use App\Ai\Context\DossierRetrievalSource;
use App\Ai\ContexteIa;
use App\Ai\PromptRepository;
use App\Ai\ProviderResolver;
use App\Ai\ResolvedModel;
use App\Models\AdminAiPrompt;
use App\Models\AiInteraction;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\User;
use App\Services\Ai\DTO\KnowledgeAnswer;
use App\Support\Ai\AiCorrelation;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Ai\AiMarkdownSanitizer;
use App\Support\Ai\AiUsage;
use DomainException;
use RuntimeException;

/**
 * `loop_knowledge_answer` — reponse documentaire sourcee (TASK-1213 / RAG V1).
 *
 * Un membre pose une question depuis une Boucle. Le service :
 * 1. verifie l'appartenance (Boucle active, meme Organization) ;
 * 2. resout provider/modele/credential de l'Organization (P4, jamais de repli) ;
 * 3. applique la garde economique ;
 * 4. fait construire le contexte par le Context Builder — la seule source
 *    autorisee est `dossier.retrieval`, tenant- et permission-safe ;
 * 5. sans extrait pertinent : repond « pas trouve dans mes sources » SANS
 *    appeler le modele (aucune hallucination possible, aucun cout) ;
 * 6. sinon interroge le SDK (Constitution → capability → AdminAiPrompt), borne
 *    la reponse et ne retient comme sources citees que les references [Sn]
 *    reellement fournies.
 *
 * Read-only : aucun LoopMessage, aucun objet metier, une seule trace P1.
 */
class LoopKnowledgeAnswerService
{
    public function __construct(
        private readonly CapabilityRegistry $capabilities,
        private readonly PromptRepository $prompts,
        private readonly ProviderResolver $providers,
        private readonly ContextBuilder $contextBuilder,
        private readonly AiEconomicGuard $economicGuard,
    ) {}

    public function answer(Loop $loop, User $requester, string $question): KnowledgeAnswer
    {
        $question = trim($question);

        if ($question === '') {
            throw new RuntimeException(__('loops.knowledge_question_required'));
        }

        $this->assertCanRequest($loop, $requester);

        $capability = CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER;
        $definition = $this->capabilities->get($capability);
        $this->capabilities->assertScopeAllowed($capability, CapabilityRegistry::SCOPE_LOOP);

        $organization = $loop->organization()->firstOrFail();

        $contexte = new ContexteIa(
            organizationId: (string) $organization->id,
            userId: (string) $requester->id,
            loopId: (string) $loop->id,
            locale: str_starts_with((string) app()->getLocale(), 'en') ? 'en' : 'fr',
            capability: $capability,
            correlationId: AiCorrelation::id(),
            source: CapabilityRegistry::SOURCE_DOSSIER_RETRIEVAL,
            query: $question,
        );

        // P4 : sans configuration IA d'Organization, aucun appel, aucun repli.
        try {
            $resolved = $this->providers->resolve($capability, $contexte);
        } catch (DomainException $exception) {
            throw new RuntimeException(__('loops.ai_not_configured_for_organization'), 0, $exception);
        }

        $verdict = $this->economicGuard->authorize(
            $organization,
            $definition->process,
            $resolved->provider,
            $resolved->model,
            (float) config('ai.knowledge.economic_guard.monthly_budget_usd', 2.00),
            (int) config('ai.knowledge.economic_guard.monthly_unknown_limit', 10),
        );

        if (! $verdict->allowed) {
            throw new RuntimeException(in_array($verdict->reason, [
                AiEconomicGuard::REASON_MONTHLY_BUDGET_REACHED,
                AiEconomicGuard::REASON_ORGANIZATION_BUDGET_REACHED,
            ], true)
                ? __('loops.ai_summary_monthly_budget_reached')
                : __('loops.ai_summary_temporarily_unavailable'));
        }

        // Le prompt administrable est requis AVANT toute depense (embedding
        // compris) : sans lui, indisponibilite explicite.
        $instructions = $this->prompts->compose($capability, $this->knowledgeInstructions());

        $borne = $this->contextBuilder->build($contexte, $definition);
        $consulted = $borne->provenanceFor(DossierRetrievalSource::NAME);

        if ($consulted === []) {
            // Rien de pertinent dans les Dossiers accessibles : on le dit, sans
            // inventer et sans appeler le modele.
            return new KnowledgeAnswer(
                answer: __('loops.knowledge_no_sources'),
                sources: [],
                consulted: [],
                grounded: false,
                interactionId: null,
            );
        }

        $agent = new LoopKnowledgeAgent(
            $instructions,
            (int) config('ai.knowledge.max_tokens', 700),
            (float) config('ai.knowledge.temperature', 0.2),
        );

        $startedAt = microtime(true);
        $prompt = $borne->text."\n\nQuestion du membre :\n".$question;

        try {
            $response = $agent->prompt(
                $prompt,
                provider: $resolved->instance,
                model: $resolved->model,
            );
        } catch (\Throwable $exception) {
            $this->recordInteraction($loop, $requester, $contexte, $definition, $resolved, $prompt, null,
                AiUsage::notObserved(), ['cost_usd' => null, 'cost_unknown' => null], 'failed', $startedAt, null,
                $exception::class, $consulted, []);

            throw new RuntimeException(__('loops.ai_error'), 0, $exception);
        }

        $answer = AiMarkdownSanitizer::sanitize(
            (string) $response->text,
            (int) config('ai.knowledge.max_answer_chars', 3000),
        );

        if ($answer === '') {
            throw new RuntimeException(__('loops.ai_empty_response'));
        }

        $usage = AiUsage::fromSdkTextTokens($response->usage->promptTokens, $response->usage->completionTokens);
        $cost = $this->economicGuard->finalize($resolved->provider, $resolved->model, $usage);

        // Citations : uniquement les references [Sn] presentes dans la
        // provenance. Une reference inventee est ignoree.
        $cited = $this->citedSources($answer, $consulted);

        $interaction = $this->recordInteraction($loop, $requester, $contexte, $definition, $resolved, $prompt,
            $answer, $usage, $cost->traceAttributes(), 'success', $startedAt, $response->invocationId, null,
            $consulted, $cited);

        return new KnowledgeAnswer(
            answer: $answer,
            sources: $cited !== [] ? $cited : $consulted,
            consulted: $consulted,
            grounded: $cited !== [],
            interactionId: $interaction->id,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $consulted
     * @return list<array<string, mixed>>
     */
    private function citedSources(string $answer, array $consulted): array
    {
        preg_match_all('/\[(S\d+)\]/u', $answer, $matches);
        $refs = array_values(array_unique($matches[1] ?? []));

        if ($refs === []) {
            return [];
        }

        return array_values(array_filter(
            $consulted,
            static fn (array $source): bool => in_array($source['ref'] ?? null, $refs, true),
        ));
    }

    private function assertCanRequest(Loop $loop, User $requester): void
    {
        $membership = LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $requester->id)
            ->where('status', 'active')
            ->exists();

        if (! $membership) {
            throw new RuntimeException(__('loops.not_an_active_member'));
        }

        if ($loop->organization_id !== $requester->organization_id) {
            throw new RuntimeException(__('loops.cross_organization'));
        }
    }

    /**
     * Instruction capability chargee depuis la source editable ; son absence
     * est une indisponibilite explicite (aucun prompt metier hardcode).
     */
    private function knowledgeInstructions(): string
    {
        $prompt = AdminAiPrompt::query()
            ->where('scenario_id', 'loop_knowledge_answer')
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();

        if ($prompt === null || trim((string) $prompt->prompt_text) === '') {
            throw new RuntimeException(__('loops.knowledge_prompt_missing'));
        }

        return (string) $prompt->prompt_text;
    }

    /**
     * @param  array{cost_usd: ?float, cost_unknown: ?bool}  $costAttributes
     * @param  list<array<string, mixed>>  $consulted
     * @param  list<array<string, mixed>>  $cited
     */
    private function recordInteraction(
        Loop $loop,
        User $requester,
        ContexteIa $contexte,
        CapabilityDefinition $definition,
        ResolvedModel $resolved,
        string $prompt,
        ?string $response,
        AiUsage $usage,
        array $costAttributes,
        string $status,
        float $startedAt,
        ?string $sdkInvocationId,
        ?string $failure,
        array $consulted,
        array $cited,
    ): AiInteraction {
        $ids = static fn (array $sources): array => array_values(array_map(
            static fn (array $s): array => ['chunk_id' => $s['chunk_id'] ?? null, 'dossier_id' => $s['dossier_id'] ?? null, 'blog_post_id' => $s['blog_post_id'] ?? null],
            $sources,
        ));

        return AiInteraction::create([
            'user_id' => $requester->id,
            'organization_id' => $contexte->organizationId,
            'correlation_id' => $contexte->correlationId,
            'process' => $definition->process,
            'feature' => 'loop_knowledge_answer',
            'model' => $resolved->trace(),
            'prompt' => $prompt,
            'response' => $response,
            'input_tokens' => $usage->inputTokensOrZero(),
            'output_tokens' => $usage->outputTokensOrZero(),
            ...$costAttributes,
            'metadata' => array_filter([
                'loop_id' => $loop->id,
                'requested_by' => $requester->id,
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'provider' => $resolved->provider,
                'capability' => $definition->id,
                'status' => $status,
                'sdk_invocation_id' => $sdkInvocationId,
                'failure' => $failure,
                'retrieval' => ['consulted' => $ids($consulted), 'cited' => $ids($cited)],
            ], static fn ($value): bool => $value !== null),
        ]);
    }
}
