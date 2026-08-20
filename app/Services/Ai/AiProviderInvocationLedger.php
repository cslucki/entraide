<?php

namespace App\Services\Ai;

use App\Ai\CapabilityRegistry;
use App\Ai\ProviderResolver;
use App\Ai\ResolvedModel;
use App\Models\AiProviderInvocation;
use App\Support\Ai\AiCost;
use App\Support\Ai\AiUsage;
use Carbon\CarbonImmutable;
use DomainException;

/**
 * Writer UNIQUE du ledger canonique `ai_provider_invocations` (TASK-1220).
 *
 * Une ligne = UNE tentative/appel provider economiquement reel, generation ou
 * embedding. Les regles que ce writer tient, et que personne d'autre ne peut
 * contourner puisque personne d'autre n'ecrit :
 *
 *  - 0 != INCONNU. Tokens non observes -> NULL. Cout non observe -> NULL +
 *    `cost_status` unknown. Un vrai zero -> 0 + known. `total_tokens` n'est
 *    somme que si input ET output sont observes : une moitie manquante ne
 *    fabrique pas un total.
 *  - CREDENTIAL PROUVE OU UNKNOWN. La source vient du registre pose par
 *    `ProviderResolver::registerInstance()` — la primitive qui pose la cle —
 *    jamais d'une inference (modele, owner, .env, nom de provider).
 *  - PAS DE LIGNE FICTIVE (NO P4). Ce writer n'est appele QUE lorsqu'un appel
 *    provider a reellement ete tente. Un refus pre-provider (pas de
 *    configuration tenant, budget atteint) n'ecrit RIEN ici.
 *  - PAS DE DEDUP PAR CORRELATION. `correlation_id` est le parent METIER :
 *    une operation qui provoque deux appels produit deux lignes. Une retry
 *    reellement envoyee est une ligne de plus, jamais une ligne ecrasee.
 *  - AUCUN SECRET, prompt ou contenu de reponse. `failure_reason` est une
 *    classe d'exception, jamais un message.
 *  - CAPABILITY CANONIQUE OU NULL (TASK-1253). `capability` est soit une
 *    capability du `CapabilityRegistry` (chemin canonique : Constitution +
 *    doctrine), soit NULL (chemin herite, dit tel quel) — jamais une
 *    etiquette inventee qui se ferait passer pour canonique. La fonction
 *    produit d'une ligne se lit `COALESCE(feature, capability)` ;
 *    `user_id` est l'ACTEUR (qui a declenche l'appel), et, quand un credit
 *    s'applique, c'est le sien (`SupervisionEconomicScope`, regle
 *    d'attribution canonique).
 *
 * Pas de try/catch silencieux : comme pour les traces P1 existantes, une
 * ecriture qui echoue est un vrai defaut qui doit se voir.
 *
 * AUTORITE ECONOMIQUE (TASK-1260, G11-b) : la garde lit ses generations ICI
 * pour les process de `AiEconomicGuard::LEDGER_AUTHORITY_SINCE_BY_PROCESS`,
 * chacun a partir de son cutover propre (perimetre a parite prouvee par
 * TASK-1259) — et toujours dans
 * `ai_interactions` avant cet instant et pour les familles heritees. La
 * double ecriture n'est pas un double comptage : les fenetres de lecture
 * sont disjointes, aucune lecture ne somme les deux tables sur une meme
 * periode. Credit (G11-c) et releves visibles (G11-d) : bascules a venir.
 */
final class AiProviderInvocationLedger
{
    public function __construct(private readonly CapabilityRegistry $capabilities) {}

    /**
     * Une tentative de GENERATION reellement envoyee au provider, reussie ou
     * non. Le credential est prouve via l'instance SDK du `ResolvedModel` :
     * elle a ete enregistree par `ProviderResolver` pendant cette meme
     * requete, sans quoi la source retombe honnetement sur `unknown`.
     */
    public function recordGeneration(
        string $organizationId,
        ?string $userId,
        ?string $capability,
        ?string $process,
        ResolvedModel $resolved,
        AiUsage $usage,
        ?AiCost $cost,
        string $status,
        ?string $correlationId,
        ?string $sdkInvocationId,
        ?string $failureReason,
        ?float $startedAtMicrotime,
        ?string $feature = null,
    ): AiProviderInvocation {
        $this->assertCanonicalOrNull($capability);

        $totalTokens = $usage->inputTokens !== null && $usage->outputTokens !== null
            ? $usage->inputTokens + $usage->outputTokens
            : null;

        return AiProviderInvocation::create([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'capability' => $capability,
            // TASK-1229 : fonction produit emettrice (semantique de
            // `ai_interactions.feature`), renseignee seulement quand elle
            // differe de la capability (essais de doctrine).
            'feature' => $feature,
            'process' => $process,
            'operation' => AiProviderInvocation::OPERATION_GENERATION,
            'embedding_operation' => null,
            'provider' => $resolved->provider,
            'model' => $resolved->model,
            'credential_source' => ProviderResolver::credentialSourceFor($resolved->instance),
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'total_tokens' => $totalTokens,
            ...$this->costColumns($cost),
            'status' => $status,
            'failure_reason' => $failureReason,
            'correlation_id' => $correlationId,
            'sdk_invocation_id' => $sdkInvocationId,
            // laravel/ai v0.7.2 n'expose aucun identifiant PROVIDER : NULL,
            // jamais l'uuid SDK a la place.
            'provider_invocation_id' => null,
            ...$this->timestampColumns($startedAtMicrotime),
        ]);
    }

    /**
     * Une tentative d'EMBEDDING reellement envoyee au provider (ingestion ou
     * query), reussie ou non. Appelee par `RecordSdkEmbeddingsInvocation`, le
     * seul observateur des invocations Embeddings du SDK.
     *
     * `$credentialSource` est resolue par l'appelant sur le nom d'instance
     * BRUT de l'evenement (avant normalisation en famille), via
     * `ProviderResolver::credentialSourceFor()`.
     *
     * Tokens : le SDK ne fournit qu'un TOTAL pour les embeddings —
     * input/output restent NULL, on n'invente pas une repartition.
     */
    public function recordEmbedding(
        string $organizationId,
        ?string $userId,
        ?string $capability,
        ?string $process,
        ?string $embeddingOperation,
        ?string $provider,
        ?string $model,
        string $credentialSource,
        ?int $totalTokens,
        ?int $embeddingCount,
        ?int $embeddingDimensions,
        ?AiCost $cost,
        string $status,
        ?string $correlationId,
        ?string $sdkInvocationId,
        ?float $startedAtMicrotime,
        ?string $feature = null,
    ): AiProviderInvocation {
        $this->assertCanonicalOrNull($capability);

        return AiProviderInvocation::create([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'capability' => $capability,
            // TASK-1229 : voir recordGeneration() — la recherche documentaire
            // d'un essai de doctrine porte `ai_doctrine_sandbox`.
            'feature' => $feature,
            'process' => $process,
            'operation' => AiProviderInvocation::OPERATION_EMBEDDING,
            'embedding_operation' => $embeddingOperation,
            'provider' => $provider,
            'model' => $model,
            'credential_source' => $credentialSource,
            'input_tokens' => null,
            'output_tokens' => null,
            'total_tokens' => $totalTokens,
            'embedding_count' => $embeddingCount,
            'embedding_dimensions' => $embeddingDimensions,
            ...$this->costColumns($cost),
            'status' => $status,
            'failure_reason' => $status === AiProviderInvocation::STATUS_FAILED
                ? 'embedding_provider_error'
                : null,
            'correlation_id' => $correlationId,
            'sdk_invocation_id' => $sdkInvocationId,
            'provider_invocation_id' => null,
            ...$this->timestampColumns($startedAtMicrotime),
        ]);
    }

    /**
     * TASK-1253 : `capability` = canonique (registre) ou NULL. Une valeur
     * non NULL inconnue du registre est un defaut du writer appelant, pas
     * une ligne a ecrire « quand meme ».
     */
    private function assertCanonicalOrNull(?string $capability): void
    {
        if ($capability !== null && ! $this->capabilities->has($capability)) {
            throw new DomainException(
                "Ledger capability [{$capability}] is not a canonical capability: pass NULL for an inherited path."
            );
        }
    }

    /**
     * Projection d'un `AiCost` (ou de son absence) vers les colonnes du
     * ledger. Un cout connu sans provenance declaree garde `cost_status`
     * known mais `cost_source` unknown : on connait le montant, pas d'ou il
     * vient — les deux affirmations restent independantes.
     *
     * @return array{provider_cost: ?float, currency: ?string, cost_status: string, cost_source: string}
     */
    private function costColumns(?AiCost $cost): array
    {
        if ($cost === null || $cost->costUnknown) {
            return [
                'provider_cost' => null,
                'currency' => null,
                'cost_status' => AiProviderInvocation::COST_UNKNOWN,
                'cost_source' => AiProviderInvocation::COST_UNKNOWN,
            ];
        }

        return [
            'provider_cost' => $cost->costUsd,
            'currency' => 'USD',
            'cost_status' => AiProviderInvocation::COST_KNOWN,
            'cost_source' => $cost->source ?? AiProviderInvocation::COST_UNKNOWN,
        ];
    }

    /**
     * @return array{started_at: ?CarbonImmutable, completed_at: CarbonImmutable}
     */
    private function timestampColumns(?float $startedAtMicrotime): array
    {
        return [
            'started_at' => $startedAtMicrotime === null
                ? null
                : CarbonImmutable::createFromTimestamp($startedAtMicrotime, date_default_timezone_get()),
            'completed_at' => CarbonImmutable::now(),
        ];
    }
}
