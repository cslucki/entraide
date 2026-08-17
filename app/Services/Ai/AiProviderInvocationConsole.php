<?php

namespace App\Services\Ai;

use App\Models\AiProviderInvocation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Lectures du ledger canonique pour les cockpits (TASK-1223).
 *
 * DEUX scopes seulement — le resume economique d'une Organization n'est PAS
 * ici : il appartient a `OrganizationAiEconomicUsage` (autorite TASK-1222),
 * et les cockpits le consomment tel quel. Ce read model ne fournit que ce
 * que l'autorite ne couvre pas :
 *
 *  - USER : la liste de SES invocations dans SON Organization (transparence,
 *    pas FinOps) ;
 *  - SUPERADMIN : des METADONNEES par Organization — comptes, sommes de
 *    couts CONNUS, jalons temporels. Jamais un prompt, une reponse, un
 *    document, un credential.
 *
 * Predicats alignes sur la review economique 1222 : un « inconnu »
 * economique est un appel REUSSI au cout non mesurable (l'echec a son
 * compteur propre) ; une invocation embedding sans operation declaree reste
 * comptee — jamais perdue entre deux filtres.
 */
final class AiProviderInvocationConsole
{
    /**
     * Invocations d'UN utilisateur dans UNE Organization, les plus recentes
     * d'abord. Le ledger est deja le niveau de verite le plus fin : chaque
     * ligne est rendue telle quelle, la vue affiche « — » pour l'absent.
     *
     * @return Collection<int, AiProviderInvocation>
     */
    public function forUser(string $organizationId, string $userId, int $limit = 50): Collection
    {
        return AiProviderInvocation::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit(max(1, min(200, $limit)))
            ->get();
    }

    /**
     * Metadonnees par Organization pour le cockpit plateforme.
     *
     * @return array<string, array{
     *   generation_count: int,
     *   embedding_ingestion_count: int,
     *   embedding_query_count: int,
     *   embedding_undeclared_count: int,
     *   failed_count: int,
     *   known_cost_usd: ?float,
     *   unknown_cost_count: int,
     *   last_activity_at: ?CarbonImmutable
     * }> indexe par organization_id
     */
    public function platformPerOrganization(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $generation = AiProviderInvocation::OPERATION_GENERATION;
        $embedding = AiProviderInvocation::OPERATION_EMBEDDING;
        $ingestion = AiProviderInvocation::EMBEDDING_OPERATION_INGESTION;
        $query = AiProviderInvocation::EMBEDDING_OPERATION_QUERY;

        $rows = AiProviderInvocation::query()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->selectRaw('organization_id')
            ->selectRaw("COUNT(CASE WHEN operation = '{$generation}' THEN 1 END) as generation_count")
            ->selectRaw("COUNT(CASE WHEN operation = '{$embedding}' AND embedding_operation = '{$ingestion}' THEN 1 END) as embedding_ingestion_count")
            ->selectRaw("COUNT(CASE WHEN operation = '{$embedding}' AND embedding_operation = '{$query}' THEN 1 END) as embedding_query_count")
            ->selectRaw("COUNT(CASE WHEN operation = '{$embedding}' AND embedding_operation IS NULL THEN 1 END) as embedding_undeclared_count")
            ->selectRaw("COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_count")
            // Somme sans ELSE 0 : NULL quand rien n'est mesure.
            ->selectRaw("SUM(CASE WHEN cost_status = 'known' THEN provider_cost END) as known_cost_usd")
            ->selectRaw("COUNT(CASE WHEN status = 'success' AND cost_status = 'unknown' THEN 1 END) as unknown_cost_count")
            ->selectRaw('MAX(created_at) as last_activity_at')
            ->groupBy('organization_id')
            ->get();

        return $rows
            ->mapWithKeys(static fn (object $row): array => [
                (string) $row->organization_id => [
                    'generation_count' => (int) $row->generation_count,
                    'embedding_ingestion_count' => (int) $row->embedding_ingestion_count,
                    'embedding_query_count' => (int) $row->embedding_query_count,
                    'embedding_undeclared_count' => (int) $row->embedding_undeclared_count,
                    'failed_count' => (int) $row->failed_count,
                    'known_cost_usd' => $row->known_cost_usd !== null ? (float) $row->known_cost_usd : null,
                    'unknown_cost_count' => (int) $row->unknown_cost_count,
                    'last_activity_at' => $row->last_activity_at !== null
                        ? CarbonImmutable::parse((string) $row->last_activity_at)
                        : null,
                ],
            ])
            ->all();
    }
}
