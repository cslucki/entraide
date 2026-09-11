<?php

namespace App\Services\Ai;

use App\Ai\Agents\ShellGeneralAnswerAgent;
use App\Ai\CapabilityDefinition;
use App\Ai\CapabilityRegistry;
use App\Ai\Context\ContextBuilder;
use App\Ai\ContexteIa;
use App\Ai\PromptRepository;
use App\Ai\ProviderResolver;
use App\Ai\ResolvedModel;
use App\Models\AiInteraction;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\DTO\ShellGeneralAnswer;
use App\Support\Ai\AiCorrelation;
use App\Support\Ai\AiCost;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Ai\AiMarkdownSanitizer;
use App\Support\Ai\AiUsage;
use DomainException;

/**
 * TASK-1526 — capability texte generale du Shell membre.
 *
 * Ce service ne gere aucune conversation : le fil et sa memoire restent chez
 * `AiShellResponder` / `AiShellThread`. Il execute seulement UNE generation
 * gouvernee : capability canonique, contexte borne, Constitution + doctrine,
 * credential du tenant, garde economique, ledger et trace P1.
 *
 * Aucune source documentaire n'est autorisee ici. Les Dossiers et Articles
 * restent servis par leurs branches pre-provider dediees ; la seule source
 * metier est l'inventaire sans identifiant des surfaces BouclePro accessibles
 * au membre.
 */
final class ShellGeneralAnswerService
{
    public const PRODUCER = 'shell.general_answer';

    public function __construct(
        private readonly CapabilityRegistry $capabilities,
        private readonly PromptRepository $prompts,
        private readonly ProviderResolver $providers,
        private readonly ContextBuilder $contextBuilder,
        private readonly AiEconomicGuard $economicGuard,
        private readonly AiProviderInvocationLedger $ledger,
    ) {}

    /** The conversational contract that produced a reusable general answer. */
    public static function contractHash(): string
    {
        $locale = str_starts_with((string) app()->getLocale(), 'en') ? 'en' : 'fr';

        return hash('sha256', trans('ai.shell_general_instructions', [], $locale));
    }

    public function answer(Organization $organization, User $requester, string $question): ShellGeneralAnswer
    {
        if ($requester->organization_id !== $organization->id) {
            throw new DomainException('The requester does not belong to this Organization.');
        }

        // Le Shell membre conservait historiquement ce coupe-circuit avant
        // tout provider. T1526 change le routage, pas l'activation du produit.
        if (! config('ai.clarify.enabled', false)) {
            throw new DomainException('AI generation is disabled for this Organization.');
        }

        $capability = CapabilityRegistry::SHELL_GENERAL_ANSWER;
        $definition = $this->capabilities->get($capability);
        $this->capabilities->assertScopeAllowed($capability, CapabilityRegistry::SCOPE_ORGANIZATION);

        $locale = str_starts_with((string) app()->getLocale(), 'en') ? 'en' : 'fr';
        $contexte = new ContexteIa(
            organizationId: (string) $organization->id,
            userId: (string) $requester->id,
            loopId: null,
            locale: $locale,
            capability: $capability,
            correlationId: AiCorrelation::id(),
            source: CapabilityRegistry::SOURCE_PRODUCT_SURFACES,
        );

        $borne = $this->contextBuilder->build($contexte, $definition);

        try {
            $resolved = $this->providers->resolve($capability, $contexte);
        } catch (DomainException $exception) {
            throw new DomainException('AI is not configured for this Organization.', 0, $exception);
        }

        // Meme seau economique que l'ancien chemin universel : le routage ne
        // cree ni nouveau budget, ni double comptage, ni cutover historique.
        $verdict = $this->economicGuard->authorize(
            $organization,
            $definition->process,
            $resolved->provider,
            $resolved->model,
            (float) config('ai.clarify.economic_guard.monthly_budget_usd', 2.00),
            (int) config('ai.clarify.economic_guard.monthly_unknown_limit', 10),
            $requester,
        );

        if (! $verdict->allowed) {
            throw new DomainException('AI generation is not available for this member.');
        }

        $instructions = $this->prompts->compose(
            $capability,
            trans('ai.shell_general_instructions', [], $locale),
            (string) $organization->id,
        );
        $doctrineVersion = $this->prompts->activeDoctrineVersion((string) $organization->id);
        $constitutionVersions = [
            'platform_constitution_version' => $this->prompts->activePlatformConstitutionVersion(),
            'org_constitution_version' => $this->prompts->activeOrganizationConstitutionVersion((string) $organization->id),
        ];

        $agent = new ShellGeneralAnswerAgent(
            $instructions,
            (int) config('ai.clarify.max_tokens', 900),
            (float) config('ai.clarify.temperature', 0.3),
        );
        $prompt = $borne->text === '' ? $question : $borne->text."\n\n".$question;
        $startedAt = microtime(true);

        try {
            $response = $agent->prompt(
                $prompt,
                provider: $resolved->instance,
                model: $resolved->model,
            );
        } catch (\Throwable $exception) {
            $this->recordInteraction(
                $requester,
                $contexte,
                $definition,
                $resolved,
                $prompt,
                null,
                AiUsage::notObserved(),
                ['cost_usd' => null, 'cost_unknown' => null],
                null,
                'failed',
                $startedAt,
                null,
                $exception::class,
                $doctrineVersion,
                $constitutionVersions,
                $borne->sourcesUsed,
                $borne->sourcesDenied,
            );

            throw new DomainException('AI generation failed.', 0, $exception);
        }

        $usage = AiUsage::fromSdkTextTokens(
            $response->usage->promptTokens,
            $response->usage->completionTokens,
        );
        $cost = $this->economicGuard->finalize($resolved->provider, $resolved->model, $usage);
        $answer = AiMarkdownSanitizer::sanitize(
            $this->stripUnsupportedDocumentCitations((string) $response->text),
            (int) config('ai.knowledge.max_answer_chars', 3000),
        );

        if ($answer === '') {
            $this->recordInteraction(
                $requester,
                $contexte,
                $definition,
                $resolved,
                $prompt,
                null,
                $usage,
                $cost->traceAttributes(),
                $cost,
                'failed',
                $startedAt,
                $response->invocationId,
                DomainException::class,
                $doctrineVersion,
                $constitutionVersions,
                $borne->sourcesUsed,
                $borne->sourcesDenied,
            );

            throw new DomainException('AI returned an empty answer.');
        }

        $interaction = $this->recordInteraction(
            $requester,
            $contexte,
            $definition,
            $resolved,
            $prompt,
            $answer,
            $usage,
            $cost->traceAttributes(),
            $cost,
            'success',
            $startedAt,
            $response->invocationId,
            null,
            $doctrineVersion,
            $constitutionVersions,
            $borne->sourcesUsed,
            $borne->sourcesDenied,
        );

        return new ShellGeneralAnswer($answer, (string) $interaction->id);
    }

    /**
     * Cette capability n'a aucune provenance documentaire. Un modele qui
     * emet malgre tout un marqueur du moteur RAG ne doit jamais fabriquer
     * l'apparence d'une citation resolue par le serveur.
     */
    private function stripUnsupportedDocumentCitations(string $answer): string
    {
        $answer = preg_replace('/\[(?:S|M)\d+\](?:\([^\r\n)]*\))?/i', '', $answer) ?? $answer;

        return preg_replace('/\s+([.,;:!?])/', '$1', $answer) ?? $answer;
    }

    /**
     * @param  array{cost_usd: ?float, cost_unknown: ?bool}  $costAttributes
     * @param  list<string>  $sourcesUsed
     * @param  array<string, string>  $sourcesDenied
     */
    private function recordInteraction(
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
        ?int $doctrineVersion,
        array $constitutionVersions,
        array $sourcesUsed,
        array $sourcesDenied,
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

        return AiInteraction::create([
            'user_id' => $requester->id,
            'organization_id' => $contexte->organizationId,
            'correlation_id' => $contexte->correlationId,
            'process' => $definition->process,
            'feature' => CapabilityRegistry::SHELL_GENERAL_ANSWER,
            'model' => $resolved->trace(),
            'prompt' => $prompt,
            'response' => $response,
            'input_tokens' => $usage->inputTokensOrZero(),
            'output_tokens' => $usage->outputTokensOrZero(),
            ...$costAttributes,
            'metadata' => array_filter([
                'requested_by' => $requester->id,
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'provider' => $resolved->provider,
                'capability' => $definition->id,
                'general_contract_hash' => self::contractHash(),
                'status' => $status,
                'sdk_invocation_id' => $sdkInvocationId,
                'failure' => $failure,
                'sources_used' => $sourcesUsed,
                'sources_denied' => $sourcesDenied,
            ], static fn ($value): bool => $value !== null)
                + ['doctrine_version' => $doctrineVersion]
                + $constitutionVersions,
        ]);
    }
}
