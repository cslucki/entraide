<?php

namespace App\Services\Ai;

use App\Models\AiProviderInvocation;
use App\Services\Ai\DTO\AiConsumptionFilters;
use Carbon\CarbonImmutable;

/**
 * Autorite economique V1 d'une Organization : generation + embeddings
 * (TASK-1222).
 *
 * DEUX registres, ZERO overlap, ZERO fusion heuristique :
 *
 *  - GENERATION : `ai_interactions`, lu avec la semantique exacte de la
 *    console TASK-1219 (`OrganizationAiConsumption`) — c'est le registre que
 *    `AiEconomicGuard` garde reellement aujourd'hui. Les generations
 *    modernes ecrivent AUSSI une ligne au ledger canonique : elles ne sont
 *    JAMAIS relues ici depuis le ledger, sans quoi elles compteraient deux
 *    fois.
 *  - EMBEDDINGS : le ledger canonique `ai_provider_invocations`
 *    (`operation = embedding`), car aucun embedding n'est ecrit dans
 *    `ai_interactions` — l'absence d'overlap est structurelle, pas
 *    heuristique.
 *
 * Invariants rendus (jamais contournables par un appelant) :
 *  - un cout connu est une SOMME de mesures `known` ; un cout inconnu est un
 *    COMPTE, jamais somme, jamais converti en 0 ;
 *  - le total connu vaut NULL tant qu'AUCUNE mesure connue n'existe : un
 *    zero affiche est toujours un vrai zero mesure ;
 *  - `failed` est compte a part : une tentative reelle peut ne rien couter
 *    de mesurable, elle n'en devient pas gratuite pour autant.
 */
final class OrganizationAiEconomicUsage
{
    public function __construct(
        private readonly OrganizationAiConsumption $generationAuthority,
    ) {}

    /**
     * @return array{
     *   from: CarbonImmutable,
     *   to: CarbonImmutable,
     *   generation: array{known_cost_usd: ?float, measured_count: int, unknown_count: int, unevaluated_count: int, trace_count: int},
     *   embedding_ingestion: array{known_cost_usd: ?float, measured_count: int, unknown_count: int, invocation_count: int, failed_count: int},
     *   embedding_query: array{known_cost_usd: ?float, measured_count: int, unknown_count: int, invocation_count: int, failed_count: int},
     *   embedding_undeclared: array{known_cost_usd: ?float, measured_count: int, unknown_count: int, invocation_count: int, failed_count: int},
     *   total_known_cost_usd: ?float,
     *   total_unknown_count: int,
     *   total_unevaluated_count: int
     * }
     */
    public function summary(string $organizationId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $generation = $this->generationSlice($organizationId, $from, $to);
        $ingestion = $this->embeddingSlice($organizationId, $from, $to, AiProviderInvocation::EMBEDDING_OPERATION_INGESTION);
        $query = $this->embeddingSlice($organizationId, $from, $to, AiProviderInvocation::EMBEDDING_OPERATION_QUERY);
        // Une invocation embedding sans operation declaree (appelant qui ne
        // pose pas la clef de trace) est de l'argent REEL : elle compte dans
        // le plafond du guard, elle doit donc apparaitre ici — dans son seau
        // « non declaree », jamais nulle part.
        $undeclared = $this->embeddingSlice($organizationId, $from, $to, null);

        $knownParts = array_filter(
            [
                $generation['known_cost_usd'],
                $ingestion['known_cost_usd'],
                $query['known_cost_usd'],
                $undeclared['known_cost_usd'],
            ],
            static fn (?float $value): bool => $value !== null,
        );

        return [
            'from' => $from,
            'to' => $to,
            'generation' => $generation,
            'embedding_ingestion' => $ingestion,
            'embedding_query' => $query,
            'embedding_undeclared' => $undeclared,
            // NULL tant qu'aucune mesure reelle n'existe : la somme d'un vide
            // n'est pas un zero.
            'total_known_cost_usd' => $knownParts === [] ? null : array_sum($knownParts),
            // Seuls les appels REUSSIS au cout non mesurable : les echecs ont
            // leur compteur dedie par tranche, ils ne se deguisent pas en
            // « inconnu » economique.
            'total_unknown_count' => $generation['unknown_count']
                + $ingestion['unknown_count']
                + $query['unknown_count']
                + $undeclared['unknown_count'],
            // Les traces generation d'avant P1-2, jamais evaluees : rendues au
            // total pour qu'un « rien d'inconnu » ne se lise pas comme « tout
            // est mesure ».
            'total_unevaluated_count' => $generation['unevaluated_count'],
        ];
    }

    /**
     * Bloc generation — delegue a l'autorite TASK-1219 pour que la meme
     * fenetre rende les memes chiffres ici et dans la console existante.
     *
     * @return array{known_cost_usd: ?float, measured_count: int, unknown_count: int, unevaluated_count: int, trace_count: int}
     */
    private function generationSlice(string $organizationId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $summary = $this->generationAuthority->summary(
            $organizationId,
            new AiConsumptionFilters($from, $to),
        );

        return [
            'known_cost_usd' => $summary['known_cost_usd'],
            'measured_count' => $summary['measured_count'],
            'unknown_count' => $summary['unknown_count'],
            'unevaluated_count' => $summary['unevaluated_count'],
            'trace_count' => $summary['trace_count'],
        ];
    }

    /**
     * @return array{known_cost_usd: ?float, measured_count: int, unknown_count: int, invocation_count: int, failed_count: int}
     */
    private function embeddingSlice(
        string $organizationId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?string $embeddingOperation,
    ): array {
        $row = AiProviderInvocation::query()
            ->where('organization_id', $organizationId)
            ->where('operation', AiProviderInvocation::OPERATION_EMBEDDING)
            ->when(
                $embeddingOperation !== null,
                static fn ($query) => $query->where('embedding_operation', $embeddingOperation),
                static fn ($query) => $query->whereNull('embedding_operation'),
            )
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            // Somme sans ELSE 0 : NULL quand rien n'est mesure.
            ->selectRaw("SUM(CASE WHEN cost_status = 'known' THEN provider_cost END) as known_cost_usd")
            ->selectRaw("COUNT(CASE WHEN cost_status = 'known' THEN 1 END) as measured_count")
            // Inconnu economique = appel REUSSI non mesurable ; l'echec a son
            // propre compteur.
            ->selectRaw("COUNT(CASE WHEN status = 'success' AND cost_status = 'unknown' THEN 1 END) as unknown_count")
            ->selectRaw('COUNT(*) as invocation_count')
            ->selectRaw("COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_count")
            ->first();

        return [
            'known_cost_usd' => $row?->known_cost_usd !== null ? (float) $row->known_cost_usd : null,
            'measured_count' => (int) ($row?->measured_count ?? 0),
            'unknown_count' => (int) ($row?->unknown_count ?? 0),
            'invocation_count' => (int) ($row?->invocation_count ?? 0),
            'failed_count' => (int) ($row?->failed_count ?? 0),
        ];
    }
}
