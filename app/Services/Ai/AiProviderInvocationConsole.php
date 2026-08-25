<?php

namespace App\Services\Ai;

use App\Models\AiInteraction;
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
     * TASK-1228 : l'historique recent d'UN utilisateur, en langage produit,
     * depuis les DEUX registres de l'autorite 1222 — sans overlap structurel :
     *  - generation : `ai_interactions` (feature/process, cout tri-etat) —
     *    ce que la garde compte ;
     *  - embeddings : le ledger canonique (recherche documentaire,
     *    indexation).
     * Aucune generation n'est relue depuis le ledger. Aucun prompt, aucune
     * reponse, aucun document, aucune cle : des metadonnees d'usage.
     *
     * TASK-1257 — STATUT d'une generation : la parole de l'ecrivain
     * (`ai_interactions.metadata.status`, chaine canonique) prime ; quand
     * elle manque (Blog IA T1247/T1248, lignes anterieures), le statut est
     * lu sur la ligne ledger `operation = generation` de la MEME
     * `correlation_id`, bornee a la meme Organization et au meme utilisateur
     * (une requete groupee). C'est un ENRICHISSEMENT de statut : aucune ligne
     * ni aucun compte ne vient du ledger pour les generations ; sans
     * correlation ou sans ligne ledger, le statut reste « — » (inconnu, jamais
     * fabrique).
     *
     * @return list<array{
     *   at: CarbonImmutable, kind: string, process: ?string, feature: ?string, sandbox: bool,
     *   model: ?string, provider: ?string, cost_state: string, cost_usd: ?float, status: ?string
     * }>
     */
    public function recentActivityForUser(string $organizationId, string $userId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));

        $generation = AiInteraction::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'created_at', 'process', 'feature', 'model', 'cost_usd', 'cost_unknown', 'metadata', 'correlation_id'])
            ->map(static function (AiInteraction $row): array {
                $model = (string) $row->model;
                $slash = strpos($model, '/');

                return [
                    'at' => CarbonImmutable::parse((string) $row->created_at),
                    'kind' => 'generation',
                    'process' => $row->process !== null ? (string) $row->process : null,
                    'feature' => $row->feature !== null ? (string) $row->feature : null,
                    'sandbox' => $row->feature === OrganizationDoctrineSandbox::FEATURE,
                    // `ai_interactions.model` = "{provider}/{model}" (ResolvedModel::trace()).
                    'provider' => is_string($row->metadata['provider'] ?? null)
                        ? $row->metadata['provider']
                        : ($slash !== false ? substr($model, 0, $slash) : null),
                    'model' => $slash !== false ? substr($model, $slash + 1) : ($model !== '' ? $model : null),
                    // Tri-etat 1132 : connu (0 legitime inclus) / inconnu / jamais evalue.
                    'cost_state' => $row->cost_unknown === null ? 'unevaluated' : ($row->cost_unknown ? 'unknown' : 'known'),
                    'cost_usd' => $row->cost_unknown === false && $row->cost_usd !== null ? (float) $row->cost_usd : null,
                    'status' => is_string($row->metadata['status'] ?? null) ? $row->metadata['status'] : null,
                    'correlation_id' => $row->correlation_id !== null ? (string) $row->correlation_id : null,
                ];
            })
            ->all();

        $generation = $this->withLedgerStatuses($organizationId, $userId, $generation);

        $embeddings = AiProviderInvocation::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('operation', AiProviderInvocation::OPERATION_EMBEDDING)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(static fn (AiProviderInvocation $row): array => [
                'at' => CarbonImmutable::parse((string) $row->created_at),
                'kind' => match ($row->embedding_operation) {
                    AiProviderInvocation::EMBEDDING_OPERATION_QUERY => 'embedding_query',
                    AiProviderInvocation::EMBEDDING_OPERATION_INGESTION => 'embedding_ingestion',
                    default => 'embedding_undeclared',
                },
                'process' => $row->process !== null ? (string) $row->process : null,
                // TASK-1229 : la feature du ledger (recherche d'un essai de
                // doctrine) — le libelle produit dit « essai de doctrine ».
                'feature' => $row->feature !== null ? (string) $row->feature : null,
                'sandbox' => $row->feature === OrganizationDoctrineSandbox::FEATURE,
                'provider' => $row->provider !== null ? (string) $row->provider : null,
                'model' => $row->model !== null ? (string) $row->model : null,
                'cost_state' => $row->cost_status === AiProviderInvocation::COST_KNOWN ? 'known' : 'unknown',
                'cost_usd' => $row->cost_status === AiProviderInvocation::COST_KNOWN && $row->provider_cost !== null ? (float) $row->provider_cost : null,
                'status' => $row->status !== null ? (string) $row->status : null,
            ])
            ->all();

        $rows = [...$generation, ...$embeddings];
        usort($rows, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        return array_slice($rows, 0, $limit);
    }

    /**
     * TASK-1257 : complete le statut des generations qui n'en portent pas
     * (`metadata.status` absent) avec celui de la ligne ledger `generation`
     * de la meme correlation — meme Organization, meme utilisateur. Une seule
     * requete pour toutes les lignes ; en cas de plusieurs lignes ledger sur
     * une correlation (nouvelle tentative), la plus recente parle. Les lignes
     * rendues restent EXACTEMENT celles de `ai_interactions` : rien n'est
     * ajoute, rien n'est compte. `correlation_id` ne sort pas de la classe.
     *
     * @param  list<array<string, mixed>>  $generation
     * @return list<array<string, mixed>>
     */
    private function withLedgerStatuses(string $organizationId, string $userId, array $generation): array
    {
        $missing = array_values(array_unique(array_filter(array_map(
            static fn (array $row): ?string => $row['status'] === null ? $row['correlation_id'] : null,
            $generation,
        ))));

        $statuses = $missing === []
            ? []
            : AiProviderInvocation::query()
                ->where('organization_id', $organizationId)
                ->where('user_id', $userId)
                ->where('operation', AiProviderInvocation::OPERATION_GENERATION)
                ->whereIn('correlation_id', $missing)
                ->whereNotNull('status')
                ->orderBy('created_at')
                ->get(['correlation_id', 'status'])
                // Cle ecrasee par la plus recente (tri croissant) : la derniere
                // tentative de la correlation donne le statut.
                ->pluck('status', 'correlation_id')
                ->all();

        return array_map(static function (array $row) use ($statuses): array {
            if ($row['status'] === null && $row['correlation_id'] !== null && isset($statuses[$row['correlation_id']])) {
                $row['status'] = (string) $statuses[$row['correlation_id']];
            }

            unset($row['correlation_id']);

            return $row;
        }, $generation);
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
