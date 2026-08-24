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
use App\Events\LoopMessageCreated;
use App\Models\AdminAiPrompt;
use App\Models\AiInteraction;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\User;
use App\Services\Ai\DTO\KnowledgeAnswer;
use App\Support\Ai\AiCorrelation;
use App\Support\Ai\AiCost;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Ai\AiMarkdownSanitizer;
use App\Support\Ai\AiRefusedException;
use App\Support\Ai\AiUsage;
use DomainException;
use Illuminate\Support\Facades\DB;
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
 * TASK-1297 : le chemin n'est PLUS read-only. L'echange est publie dans le
 * fil de la Boucle sur le modele exact de ChatLoopAiService::ask() : la
 * question du membre (type `user`, si `ai.knowledge.publish_question` —
 * reversible en une ligne) puis la reponse documentaire liee (type `ai`,
 * `reply_to_id`, sources publiques en metadata). Rien n'est publie quand rien
 * n'a coute : pas de sources -> aucun appel, aucune interaction, aucun
 * message ; echec provider ou reponse vide -> aucun message, la trace seule.
 * Aucun autre objet metier, une seule trace P1.
 */
class LoopKnowledgeAnswerService
{
    public function __construct(
        private readonly CapabilityRegistry $capabilities,
        private readonly PromptRepository $prompts,
        private readonly ProviderResolver $providers,
        private readonly ContextBuilder $contextBuilder,
        private readonly AiEconomicGuard $economicGuard,
        private readonly AiProviderInvocationLedger $ledger,
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
        // TASK-1229 : etat « credential absent », code stable, distinct des
        // deux refus economiques ci-dessous.
        try {
            $resolved = $this->providers->resolve($capability, $contexte);
        } catch (DomainException $exception) {
            throw AiRefusedException::notConfigured($exception);
        }

        // TASK-1229 : le demandeur est passe a la garde — son credit IA du
        // mois (utilisations) s'applique ICI, dans l'autorite existante, avant
        // toute recherche documentaire et toute generation.
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
            // Trois etats, trois messages, trois codes : credit utilisateur
            // epuise / budget Organization atteint / autre indisponibilite.
            throw AiRefusedException::fromVerdict($verdict);
        }

        // Le prompt administrable est requis AVANT toute depense (embedding
        // compris) : sans lui, indisponibilite explicite.
        // TASK-1227 : doctrine active de l'Organization composee sous la
        // Constitution ; la regle « repondre depuis les sources [S] » reste
        // appliquee en code (citations revalidees), la doctrine ne peut pas
        // autoriser d'inventer.
        $instructions = $this->prompts->compose($capability, $this->knowledgeInstructions(), (string) $organization->id);
        // TASK-1236 : version de doctrine reellement composee ci-dessus, tracee
        // sur l'interaction enregistree plutot que reconstituee a posteriori.
        $doctrineVersion = $this->prompts->activeDoctrineVersion((string) $organization->id);

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
                // TASK-1229 : la recherche documentaire a pu etre emise (une
                // utilisation reelle) : le credit se lit ici aussi.
                credit: $this->economicGuard->userCreditStatus($organization, $requester),
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
                AiUsage::notObserved(), ['cost_usd' => null, 'cost_unknown' => null], null, 'failed', $startedAt, null,
                $exception::class, $consulted, [], $doctrineVersion);

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
            $answer, $usage, $cost->traceAttributes(), $cost, 'success', $startedAt, $response->invocationId, null,
            $consulted, $cited, $doctrineVersion);

        $sources = $cited !== [] ? $cited : $consulted;

        $this->publishExchange($loop, $requester, $question, $answer, $resolved, $interaction, $cited, $sources);

        return new KnowledgeAnswer(
            answer: $answer,
            sources: $sources,
            consulted: $consulted,
            grounded: $cited !== [],
            interactionId: $interaction->id,
            // TASK-1229 : le credit APRES cette reponse (recherche + generation
            // decomptees) — l'alerte de seuil se lit ici, l'action n'a pas
            // ete bloquee.
            credit: $this->economicGuard->userCreditStatus($organization, $requester),
        );
    }

    /**
     * TASK-1297 : publication de l'echange dans le fil, calquee sur
     * ChatLoopAiService::ask() — la question du membre (type `user`) puis la
     * reponse (type `ai`) liee par `reply_to_id`, sender_id null,
     * organization_id de la Boucle, sources publiques et provenance en
     * metadata. N'est appelee QU'APRES une generation reussie et sa trace :
     * un refus, un echec provider ou une reponse vide n'arrivent jamais ici.
     *
     * @param  list<array<string, mixed>>  $cited
     * @param  list<array<string, mixed>>  $sources
     */
    private function publishExchange(
        Loop $loop,
        User $requester,
        string $question,
        string $answer,
        ResolvedModel $resolved,
        AiInteraction $interaction,
        array $cited,
        array $sources,
    ): void {
        DB::transaction(function () use ($loop, $requester, $question, $answer, $resolved, $interaction, $cited, $sources): void {
            $questionMessage = null;

            // La ligne de reversibilite (gouvernance 24/08) : false = seule la
            // reponse est publiee, la question restant en metadata.
            if ((bool) config('ai.knowledge.publish_question', true)) {
                $questionMessage = LoopMessage::create([
                    'loop_id' => $loop->id,
                    'sender_id' => $requester->id,
                    'reply_to_id' => null,
                    'body' => $question,
                    'image_path' => null,
                    'type' => 'user',
                    'metadata' => [
                        'asked_knowledge_question' => true,
                    ],
                    'organization_id' => $loop->organization_id,
                ]);
            }

            $message = LoopMessage::create([
                'loop_id' => $loop->id,
                'sender_id' => null,
                'reply_to_id' => $questionMessage?->id,
                'body' => $answer,
                'image_path' => null,
                'type' => 'ai',
                'metadata' => [
                    'requested_by' => $requester->id,
                    'action' => 'knowledge',
                    'question' => $question,
                    'grounded' => $cited !== [],
                    'sources' => array_map(KnowledgeAnswer::publicSource(...), $sources),
                    'provider' => $resolved->provider,
                    'model' => $resolved->model,
                    'ai_interaction_id' => $interaction->id,
                ],
                'organization_id' => $loop->organization_id,
            ]);

            event(new LoopMessageCreated($message));

            $loop->touch();
        });
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
        ?AiCost $cost,
        string $status,
        float $startedAt,
        ?string $sdkInvocationId,
        ?string $failure,
        array $consulted,
        array $cited,
        ?int $doctrineVersion,
    ): AiInteraction {
        // TASK-1220 : ligne canonique du ledger, memes points que la trace P1
        // (succes ET echec) ; les refus pre-provider n'arrivent jamais ici.
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
            ], static fn ($value): bool => $value !== null)
                // TASK-1236 : cle toujours presente, meme a null (aucune doctrine
                // active) — sa PRESENCE distingue une interaction tracee d'une
                // ligne anterieure au mecanisme, ce qu'un array_filter effacerait.
                + ['doctrine_version' => $doctrineVersion],
        ]);
    }
}
