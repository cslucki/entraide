<?php

namespace App\Services\Ai;

use App\Ai\ResolvedModel;
use App\Models\AiProviderInvocation;
use App\Support\Ai\AiCorrelation;
use App\Support\Ai\AiCost;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Ai\AiPricingCatalog;
use App\Support\Ai\AiRefusedException;
use App\Support\Ai\AiUsage;
use Closure;

/**
 * AUTORITE ECONOMIQUE d'un appel herite paye par la cle PLATEFORME
 * (TASK-1251, extraite du decorateur `EconomicSupervisionProvider` de
 * TASK-1250 pour etre reutilisee par les chemins qui N'EMPRUNTENT PAS le
 * contrat `SupervisionProvider` — `MemberProfileAgentResponder` fait ses
 * propres appels HTTP avec son prompt de profil) :
 *
 *   GARDE (`authorize()` : `AiEconomicGuard`, aucun appel si refus, rien d'ecrit)
 *     -> TENTATIVE (`attempt()` : l'appel provider reel, UNE fois)
 *     -> LEDGER canonique `ai_provider_invocations` (succes ET echec).
 *
 * Tout ce que cette classe sait du perimetre vient d'un `SupervisionEconomicScope`
 * EXPLICITE (tenant de record, acteur, credit, feature) — rien n'est devine
 * depuis `current_organization` ni `auth()`, ce qui est la condition pour
 * qu'elle serve aussi bien une requete HTTP qu'un JOB en file (aucun
 * utilisateur connecte, aucune Organization courante dans un worker).
 *
 * Elle ne sait rien du credential : le `ResolvedModel` qu'on lui passe porte
 * le nom d'instance du registre de preuve pose par
 * `SupervisionProviderResolver::declarePlatformCredential()` (cle lue dans la
 * configuration PLATEFORME et declaree telle quelle — jamais deduite). Le
 * registre vit dans `Context`, scope par requete ET par job (hydrate a chaque
 * `JobProcessing`) : declaration et ecriture du ledger ont lieu dans le meme
 * scope, la source est donc prouvee ou honnetement `unknown`.
 *
 * Couts : usage OBSERVE fourni par l'appelant (`usageOf`) -> cout catalogue ;
 * usage non observe -> `cost_status = unknown` (ou `known 0` si le catalogue
 * declare le provider gratuit, ollama) — jamais un 0 fabrique. Un echec
 * (Throwable apres depart de la requete) est une tentative economiquement
 * reelle : ligne `failed`, cout NULL/unknown, `failure_reason` = classe
 * d'exception, puis l'exception d'origine est relancee telle quelle. Les
 * retries internes d'un provider restent invisibles d'ici : une tentative =
 * une ligne.
 */
final class SupervisionEconomicAuthority
{
    public function __construct(
        private readonly AiEconomicGuard $guard,
        private readonly AiProviderInvocationLedger $ledger,
        private readonly SupervisionEconomicScope $scope,
    ) {}

    public function scope(): SupervisionEconomicScope
    {
        return $this->scope;
    }

    /**
     * GARDE AVANT PROVIDER : budget mensuel de l'Organization de record,
     * budget/quota d'inconnus du process (`config('ai.supervision_resolver.
     * economic_guard')`, par process), credit du `creditUser` s'il y en a un.
     * Un refus n'ecrit rien : ni ledger, ni trace — un appel qui n'est pas
     * parti n'est pas une utilisation.
     *
     * @throws AiRefusedException
     */
    public function authorize(string $process, ResolvedModel $resolved): void
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

    /**
     * UNE tentative provider reelle, sous ledger. A n'appeler qu'APRES
     * `authorize()` : ici on ne refuse plus, on constate.
     *
     * - `$call` : l'appel provider. Tout Throwable = echec APRES depart ->
     *   ligne `failed` (cout NULL/unknown, classe d'exception), relance.
     * - `$usageOf` : l'usage OBSERVE du resultat (`AiUsage::notObserved()` si
     *   le provider/contrat ne rapporte rien — le catalogue tranche).
     * - `$reportedModelOf` : le modele RAPPORTE par le provider s'il en
     *   rapporte un (celui que son propre calcul a utilise), sinon null ->
     *   modele demande.
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @param  Closure(T): AiUsage  $usageOf
     * @param  (Closure(T): ?string)|null  $reportedModelOf
     * @return T
     *
     * - `$capability` (TASK-1285, additif) : la capability CANONIQUE du chemin
     *   quand il en a une — regle ecrite de TASK-1253 : quand un chemin herite
     *   entre au registre, « le writer concerne doit alors la porter en
     *   capability ». NULL (defaut) pour les chemins qui n'en ont pas : leur
     *   ledger le dit tel quel, comme avant.
     *
     * @throws \Throwable l'exception du provider, telle quelle, apres la ligne `failed`
     */
    public function attempt(string $process, ResolvedModel $resolved, Closure $call, Closure $usageOf, ?Closure $reportedModelOf = null, ?string $capability = null): mixed
    {
        $startedAt = microtime(true);
        $correlationId = AiCorrelation::id();

        try {
            $result = $call();
        } catch (\Throwable $exception) {
            $this->record($process, $resolved, AiUsage::notObserved(), null,
                AiProviderInvocation::STATUS_FAILED, $correlationId, $exception::class, $startedAt, $capability);

            throw $exception;
        }

        $reportedModel = $reportedModelOf !== null ? trim((string) $reportedModelOf($result)) : '';
        $reported = $reportedModel !== ''
            ? new ResolvedModel($resolved->provider, $reportedModel, $resolved->instance)
            : $resolved;

        $usage = $usageOf($result);
        $cost = AiPricingCatalog::cost($reported->provider, $reported->model, $usage);

        $this->record($process, $reported, $usage, $cost,
            AiProviderInvocation::STATUS_SUCCESS, $correlationId, null, $startedAt, $capability);

        return $result;
    }

    /**
     * Ligne canonique du ledger — une par appel provider reellement tente.
     * `capability` : celle du chemin canonique quand l'appelant la porte
     * (TASK-1285 — le registre la valide, `assertCanonicalOrNull`), NULL
     * sinon : ce chemin n'est pas une capability canonique et le dit tel
     * quel ; `process` = celui de la trace operationnelle du chemin,
     * `feature` = la fonction produit du perimetre, `user_id` = l'acteur.
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
        ?string $capability = null,
    ): void {
        $this->ledger->recordGeneration(
            organizationId: (string) $this->scope->organization->id,
            userId: $this->scope->actor?->id !== null ? (string) $this->scope->actor->id : null,
            capability: $capability,
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
