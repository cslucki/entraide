<?php

namespace App\Services\Ai\Providers;

use App\Ai\ResolvedModel;
use App\Models\AiProviderInvocation;
use App\Services\Ai\AiProviderInvocationLedger;
use App\Services\Ai\Contracts\AiScenarioDefinition;
use App\Services\Ai\Contracts\SupervisionProvider;
use App\Services\Ai\DTO\AiSupervisionResult;
use App\Services\Ai\SupervisionEconomicScope;
use App\Support\Ai\AiCorrelation;
use App\Support\Ai\AiCost;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Ai\AiPricingCatalog;
use App\Support\Ai\AiProcess;
use App\Support\Ai\AiRefusedException;
use App\Support\Ai\AiUsage;

/**
 * AUTORITE ECONOMIQUE des appels herites `SupervisionProvider` (TASK-1250) —
 * le meme patron que `BlogAiService::callAi()` (T1247) et
 * `BlogExplorerController::callProvider()` (T1248), pose UNE fois en
 * decorateur plutot que recopie dans chaque appelant :
 *
 *   GARDE (`AiEconomicGuard::authorize()`, aucun appel si refus, rien d'ecrit)
 *     -> appel provider (inner : `LoggingSupervisionProvider` -> HTTP)
 *     -> LEDGER canonique `ai_provider_invocations` (succes ET echec).
 *
 * Ce decorateur ne sait rien du credential : il recoit le nom d'instance du
 * registre de preuve pose par `SupervisionProviderResolver::declarePlatformCredential()`
 * (qui a lu la cle dans la configuration PLATEFORME et l'a declaree telle
 * quelle — jamais deduite). Il ne touche ni au contrat `SupervisionProvider`,
 * ni aux providers concrets, ni a la trace operationnelle `admin_ai_interactions`
 * (toujours ecrite par `LoggingSupervisionProvider`, inchangee) : la ligne du
 * ledger ne porte ni contenu ni cout invente.
 *
 * Couts : `supervise()` rapporte un usage observe -> cout catalogue ;
 * `runScenario()` ne rapporte AUCUN usage (contrat du provider, voir la note
 * TASK-1132 de `LoggingSupervisionProvider`) -> `cost_status = unknown`
 * (ou `known 0` si le catalogue declare le provider gratuit, ollama) — jamais
 * un 0 fabrique. Un echec (SupervisionException apres depart de la requete,
 * ou tout autre Throwable) est une tentative economiquement reelle : ligne
 * `failed`, cout NULL/unknown, `failure_reason` = classe d'exception, puis
 * l'exception d'origine est relancee telle quelle. Les retries internes des
 * providers (429) restent invisibles d'ici : une invocation = une ligne.
 */
final class EconomicSupervisionProvider implements SupervisionProvider
{
    public function __construct(
        private readonly SupervisionProvider $inner,
        private readonly AiEconomicGuard $guard,
        private readonly AiProviderInvocationLedger $ledger,
        private readonly SupervisionEconomicScope $scope,
        /** Famille du provider (`openai`, `openrouter`, `ollama`) : trace + catalogue. */
        private readonly string $provider,
        /** Modele du provider quand l'appelant n'en impose pas (`providerConfig()['model']`). */
        private readonly string $defaultModel,
        /** Cle du registre de preuve du credential (`legacy:platform:{provider}`). */
        private readonly string $credentialInstance,
    ) {}

    public function supervise(string $content, ?string $model = null): AiSupervisionResult
    {
        $process = AiProcess::fromScenarioId('supervision_content');
        $requested = $this->resolvedModel($model);

        $this->authorize($process, $requested);

        $startedAt = microtime(true);
        $correlationId = AiCorrelation::id();

        try {
            $result = $this->inner->supervise($content, $model);
        } catch (\Throwable $exception) {
            $this->record($process, $requested, AiUsage::notObserved(), null,
                AiProviderInvocation::STATUS_FAILED, $correlationId, $exception::class, $startedAt);

            throw $exception;
        }

        // Le modele RAPPORTE par le provider (celui que son propre calcul de
        // cout a utilise), sinon le modele demande.
        $reported = trim($result->model) !== ''
            ? new ResolvedModel($this->provider, $result->model, $this->credentialInstance)
            : $requested;
        $usage = self::usageOf($result);
        $cost = AiPricingCatalog::cost($this->provider, $reported->model, $usage);

        $this->record($process, $reported, $usage, $cost,
            AiProviderInvocation::STATUS_SUCCESS, $correlationId, null, $startedAt);

        return $result;
    }

    public function runScenario(AiScenarioDefinition $scenario, string $content, ?string $model = null): array
    {
        $process = AiProcess::fromScenarioId($scenario->id());
        $resolved = $this->resolvedModel($model);

        $this->authorize($process, $resolved);

        $startedAt = microtime(true);
        $correlationId = AiCorrelation::id();

        try {
            $result = $this->inner->runScenario($scenario, $content, $model);
        } catch (\Throwable $exception) {
            $this->record($process, $resolved, AiUsage::notObserved(), null,
                AiProviderInvocation::STATUS_FAILED, $correlationId, $exception::class, $startedAt);

            throw $exception;
        }

        // Usage structurellement NON observe sur ce contrat : le catalogue
        // tranche (UNKNOWN, ou 0 connu pour un provider declare gratuit).
        $usage = AiUsage::notObserved();
        $cost = AiPricingCatalog::cost($this->provider, $resolved->model, $usage);

        $this->record($process, $resolved, $usage, $cost,
            AiProviderInvocation::STATUS_SUCCESS, $correlationId, null, $startedAt);

        return $result;
    }

    /**
     * GARDE AVANT PROVIDER : budget mensuel de l'Organization de record,
     * budget/quota d'inconnus du process, credit du `creditUser` s'il y en a
     * un. Un refus n'ecrit rien : ni ledger, ni trace — un appel qui n'est
     * pas parti n'est pas une utilisation.
     *
     * @throws AiRefusedException
     */
    private function authorize(string $process, ResolvedModel $resolved): void
    {
        $verdict = $this->guard->authorize(
            $this->scope->organization,
            $process,
            $resolved->provider,
            $resolved->model,
            (float) config('ai.supervision_resolver.economic_guard.monthly_budget_usd', 2.00),
            (int) config('ai.supervision_resolver.economic_guard.monthly_unknown_limit', 10),
            $this->scope->creditUser,
        );

        if (! $verdict->allowed) {
            throw AiRefusedException::fromVerdict($verdict);
        }
    }

    private function resolvedModel(?string $model): ResolvedModel
    {
        $name = trim((string) $model) !== '' ? (string) $model : $this->defaultModel;

        return new ResolvedModel($this->provider, $name, $this->credentialInstance);
    }

    /**
     * Usage observe d'un `AiSupervisionResult`. Le DTO type ses compteurs
     * `int` (0 par defaut) : un 0 y est indiscernable d'un compteur absent
     * DANS L'OBJET. On retablit la distinction ici, compteur par compteur,
     * par le meme raisonnement que `AiUsage::fromSdkTextTokens()` : une
     * generation reelle ne consomme jamais 0 token — un 0 signe un compteur
     * non rapporte (ollama ne rapporte pas l'entree, par exemple) et reste
     * NULL au ledger plutot qu'un zero fabrique.
     */
    private static function usageOf(AiSupervisionResult $result): AiUsage
    {
        return AiUsage::of(
            $result->inputTokens > 0 ? $result->inputTokens : null,
            $result->outputTokens > 0 ? $result->outputTokens : null,
        );
    }

    /**
     * Ligne canonique du ledger — une par appel provider reellement tente.
     * `capability` NULL : ce chemin n'est pas une capability canonique (il le
     * dit tel quel) ; `process` = celui de la trace operationnelle (meme
     * `AiProcess::fromScenarioId()`), `feature` = la fonction produit du
     * perimetre.
     */
    private function record(
        string $process,
        ResolvedModel $resolved,
        AiUsage $usage,
        ?AiCost $cost,
        string $status,
        string $correlationId,
        ?string $failureReason,
        float $startedAt,
    ): void {
        $this->ledger->recordGeneration(
            organizationId: (string) $this->scope->organization->id,
            userId: $this->scope->actor?->id !== null ? (string) $this->scope->actor->id : null,
            capability: null,
            process: $process,
            resolved: $resolved,
            usage: $usage,
            cost: $cost,
            status: $status,
            correlationId: $correlationId,
            sdkInvocationId: null,
            failureReason: $failureReason,
            startedAtMicrotime: $startedAt,
            feature: $this->scope->feature,
        );
    }
}
