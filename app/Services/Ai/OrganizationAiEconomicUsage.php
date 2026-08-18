<?php

namespace App\Services\Ai;

use App\Models\AiProviderInvocation;
use App\Services\Ai\DTO\AiConsumptionFilters;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

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
    public function summary(string $organizationId, CarbonImmutable $from, CarbonImmutable $to, ?string $userId = null): array
    {
        // TASK-1228 : `$userId` restreint la MEME lecture a un utilisateur
        // (son releve « Mes usages IA ») : generation par le filtre tenant-safe
        // de l'autorite 1219, embeddings par `user_id` sur des lignes deja
        // bornees a l'Organization. Aucune autre semantique.
        $generation = $this->generationSlice($organizationId, $from, $to, $userId);
        $ingestion = $this->embeddingSlice($organizationId, $from, $to, AiProviderInvocation::EMBEDDING_OPERATION_INGESTION, $userId);
        $query = $this->embeddingSlice($organizationId, $from, $to, AiProviderInvocation::EMBEDDING_OPERATION_QUERY, $userId);
        // Une invocation embedding sans operation declaree (appelant qui ne
        // pose pas la clef de trace) est de l'argent REEL : elle compte dans
        // le plafond du guard, elle doit donc apparaitre ici — dans son seau
        // « non declaree », jamais nulle part.
        $undeclared = $this->embeddingSlice($organizationId, $from, $to, null, $userId);

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
            // TASK-1228 : SOUS-ENSEMBLE de `generation` — les essais de doctrine
            // (bac a sable 1227). Reels, comptes dans le budget, distingues a
            // l'ecran, jamais retranches ni ajoutes.
            'generation_sandbox' => $this->generationAuthority->sandboxSummary(
                $organizationId,
                new AiConsumptionFilters($from, $to, $userId),
            ),
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
     * TASK-1228 : ventilation par utilisateur de la MEME autorite — generation
     * (1219 `byUser`, attribution PROUVEE par `ai_interactions.user_id` et
     * defense tenant sur `users.organization_id`) + embeddings du ledger,
     * attribues par `user_id` avec la MEME defense tenant. Une trace dont
     * l'utilisateur n'appartient pas a l'Organization, ou sans utilisateur,
     * remonte sous `user_id = null` (« non attribuable ») : comptee, jamais
     * repartie au prorata, jamais nommee.
     *
     * Invariant (teste) : la somme des lignes (attribuables + non attribuable)
     * = `summary()` de l'Organization sur la meme fenetre.
     *
     * @return list<array{
     *   user_id: ?string, name: ?string,
     *   generation: array{known_cost_usd: ?float, measured_count: int, unknown_count: int, unevaluated_count: int, trace_count: int},
     *   embedding_query: array{known_cost_usd: ?float, measured_count: int, unknown_count: int, invocation_count: int, failed_count: int},
     *   embedding_ingestion: array{known_cost_usd: ?float, measured_count: int, unknown_count: int, invocation_count: int, failed_count: int},
     *   embedding_undeclared: array{known_cost_usd: ?float, measured_count: int, unknown_count: int, invocation_count: int, failed_count: int},
     *   total_known_cost_usd: ?float, total_unknown_count: int, total_unevaluated_count: int, total_count: int
     * }>
     */
    public function byUser(string $organizationId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = [];

        foreach ($this->generationAuthority->byUser($organizationId, new AiConsumptionFilters($from, $to)) as $row) {
            $key = $row['user_id'] ?? '';
            $rows[$key] = $this->emptyUserRow($row['user_id'], $row['name']);
            $rows[$key]['generation'] = [
                'known_cost_usd' => $row['known_cost_usd'],
                'measured_count' => $row['measured_count'],
                'unknown_count' => $row['unknown_count'],
                'unevaluated_count' => $row['unevaluated_count'],
                'trace_count' => $row['trace_count'],
            ];
        }

        $embeddings = DB::table('ai_provider_invocations')
            ->leftJoin('users', function ($join) use ($organizationId): void {
                $join->on('users.id', '=', 'ai_provider_invocations.user_id')
                    ->where('users.organization_id', '=', $organizationId);
            })
            ->where('ai_provider_invocations.organization_id', $organizationId)
            ->where('ai_provider_invocations.operation', AiProviderInvocation::OPERATION_EMBEDDING)
            ->where('ai_provider_invocations.created_at', '>=', $from)
            ->where('ai_provider_invocations.created_at', '<', $to)
            ->selectRaw('users.id as user_id')
            ->selectRaw('MAX(users.name) as name')
            ->selectRaw('ai_provider_invocations.embedding_operation as embedding_operation')
            ->selectRaw($this->embeddingSelect())
            ->groupBy('users.id', 'ai_provider_invocations.embedding_operation')
            ->get();

        foreach ($embeddings as $row) {
            $key = $row->user_id !== null ? (string) $row->user_id : '';
            $rows[$key] ??= $this->emptyUserRow($row->user_id !== null ? (string) $row->user_id : null, $row->name !== null ? (string) $row->name : null);
            $bucket = match ($row->embedding_operation) {
                AiProviderInvocation::EMBEDDING_OPERATION_QUERY => 'embedding_query',
                AiProviderInvocation::EMBEDDING_OPERATION_INGESTION => 'embedding_ingestion',
                default => 'embedding_undeclared',
            };
            $rows[$key][$bucket] = $this->embeddingRow($row);
        }

        $rows = array_map(fn (array $row): array => $this->withUserTotals($row), $rows);

        usort($rows, static function (array $a, array $b): int {
            // Non attribuable en dernier ; puis cout connu decroissant ; puis volume.
            if (($a['user_id'] === null) !== ($b['user_id'] === null)) {
                return $a['user_id'] === null ? 1 : -1;
            }

            return [(float) ($b['total_known_cost_usd'] ?? -1), $b['total_count']]
                <=> [(float) ($a['total_known_cost_usd'] ?? -1), $a['total_count']];
        });

        return array_values($rows);
    }

    /**
     * TASK-1228 : la MEME autorite, groupee par Organization, pour le cockpit
     * plateforme. Generation depuis `ai_interactions` (1219 `byOrganization`),
     * embeddings depuis le ledger, memes predicats que `summary()` — de sorte
     * que la ligne d'une Organization ici EST son `summary()` (teste), et que
     * `totals` EST la somme des lignes plus les traces sans Organization.
     *
     * Aucun contenu tenant : des comptes et des sommes de couts connus.
     *
     * @return array{
     *   organizations: array<string, array<string, mixed>>,
     *   unattributed: array<string, mixed>,
     *   totals: array{known_cost_usd: ?float, unknown_count: int, unevaluated_count: int, generation_count: int, generation_sandbox_count: int, embedding_query_count: int, embedding_ingestion_count: int, embedding_undeclared_count: int, ai_users_count: int, active_organizations_count: int}
     * }
     */
    public function perOrganization(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $generation = $this->generationAuthority->byOrganization($from, $to);
        $sandbox = $this->generationAuthority->byOrganization($from, $to, sandboxOnly: true);

        $embeddings = DB::table('ai_provider_invocations')
            ->where('operation', AiProviderInvocation::OPERATION_EMBEDDING)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->selectRaw('organization_id')
            ->selectRaw('embedding_operation')
            ->selectRaw($this->embeddingSelect())
            ->groupBy('organization_id', 'embedding_operation')
            ->get();

        $users = $this->aiUsersByOrganization($from, $to);

        $organizations = [];
        $emptyGeneration = ['known_cost_usd' => null, 'measured_count' => 0, 'unknown_count' => 0, 'unevaluated_count' => 0, 'trace_count' => 0];

        foreach ($generation as $key => $row) {
            $organizations[$key] = $this->emptyOrganizationRow();
            $organizations[$key]['generation'] = $row;
        }

        foreach ($sandbox as $key => $row) {
            $organizations[$key] ??= $this->emptyOrganizationRow();
            $organizations[$key]['generation_sandbox'] = $row;
        }

        foreach ($embeddings as $row) {
            $key = (string) $row->organization_id;
            $organizations[$key] ??= $this->emptyOrganizationRow();
            $bucket = match ($row->embedding_operation) {
                AiProviderInvocation::EMBEDDING_OPERATION_QUERY => 'embedding_query',
                AiProviderInvocation::EMBEDDING_OPERATION_INGESTION => 'embedding_ingestion',
                default => 'embedding_undeclared',
            };
            $organizations[$key][$bucket] = $this->embeddingRow($row);
        }

        foreach ($users as $key => $count) {
            $organizations[$key] ??= $this->emptyOrganizationRow();
            $organizations[$key]['ai_users_count'] = $count;
        }

        $organizations = array_map(fn (array $row): array => $this->withOrganizationTotals($row), $organizations);

        $unattributed = $organizations[''] ?? $this->withOrganizationTotals($this->emptyOrganizationRow());
        unset($organizations['']);

        $all = [...array_values($organizations), $unattributed];
        $knownParts = array_filter(
            array_map(static fn (array $row): ?float => $row['total_known_cost_usd'], $all),
            static fn (?float $value): bool => $value !== null,
        );

        return [
            'organizations' => $organizations,
            'unattributed' => $unattributed,
            'totals' => [
                'known_cost_usd' => $knownParts === [] ? null : array_sum($knownParts),
                'unknown_count' => array_sum(array_column($all, 'total_unknown_count')),
                'unevaluated_count' => array_sum(array_column($all, 'total_unevaluated_count')),
                'generation_count' => array_sum(array_map(static fn (array $r): int => $r['generation']['trace_count'], $all)),
                'generation_sandbox_count' => array_sum(array_map(static fn (array $r): int => $r['generation_sandbox']['trace_count'], $all)),
                'embedding_query_count' => array_sum(array_map(static fn (array $r): int => $r['embedding_query']['invocation_count'], $all)),
                'embedding_ingestion_count' => array_sum(array_map(static fn (array $r): int => $r['embedding_ingestion']['invocation_count'], $all)),
                'embedding_undeclared_count' => array_sum(array_map(static fn (array $r): int => $r['embedding_undeclared']['invocation_count'], $all)),
                // Distincts par Organization, sommes : un meme humain dans deux
                // Organizations est deux utilisateurs IA (deux budgets).
                'ai_users_count' => array_sum(array_column($all, 'ai_users_count')),
                'active_organizations_count' => count(array_filter(
                    $organizations,
                    static fn (array $row): bool => $row['total_count'] > 0,
                )),
            ],
        ];
    }

    /**
     * Utilisateurs IA distincts par Organization sur la fenetre : union des
     * `user_id` des deux registres (generation + embeddings). Compte, jamais
     * un nom. Cle `''` pour les traces sans Organization.
     *
     * @return array<string, int>
     */
    private function aiUsersByOrganization(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $generation = DB::table('ai_interactions')
            ->select('organization_id', 'user_id')
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->whereNotNull('user_id');

        $embedding = DB::table('ai_provider_invocations')
            ->select('organization_id', 'user_id')
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->whereNotNull('user_id');

        $rows = DB::query()
            ->fromSub($generation->union($embedding), 'ai_users')
            ->selectRaw('organization_id')
            ->selectRaw('COUNT(DISTINCT user_id) as users_count')
            ->groupBy('organization_id')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $result[$row->organization_id !== null ? (string) $row->organization_id : ''] = (int) $row->users_count;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyOrganizationRow(): array
    {
        $generation = ['known_cost_usd' => null, 'measured_count' => 0, 'unknown_count' => 0, 'unevaluated_count' => 0, 'trace_count' => 0];
        $embedding = ['known_cost_usd' => null, 'measured_count' => 0, 'unknown_count' => 0, 'invocation_count' => 0, 'failed_count' => 0];

        return [
            'generation' => $generation,
            'generation_sandbox' => $generation,
            'embedding_ingestion' => $embedding,
            'embedding_query' => $embedding,
            'embedding_undeclared' => $embedding,
            'ai_users_count' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function withOrganizationTotals(array $row): array
    {
        $knownParts = array_filter(
            [
                $row['generation']['known_cost_usd'],
                $row['embedding_ingestion']['known_cost_usd'],
                $row['embedding_query']['known_cost_usd'],
                $row['embedding_undeclared']['known_cost_usd'],
            ],
            static fn (?float $value): bool => $value !== null,
        );

        $row['total_known_cost_usd'] = $knownParts === [] ? null : array_sum($knownParts);
        $row['total_unknown_count'] = $row['generation']['unknown_count']
            + $row['embedding_ingestion']['unknown_count']
            + $row['embedding_query']['unknown_count']
            + $row['embedding_undeclared']['unknown_count'];
        $row['total_unevaluated_count'] = $row['generation']['unevaluated_count'];
        $row['total_count'] = $row['generation']['trace_count']
            + $row['embedding_ingestion']['invocation_count']
            + $row['embedding_query']['invocation_count']
            + $row['embedding_undeclared']['invocation_count'];

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyUserRow(?string $userId, ?string $name): array
    {
        $row = $this->emptyOrganizationRow();
        unset($row['ai_users_count'], $row['generation_sandbox']);

        return ['user_id' => $userId, 'name' => $name, ...$row];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function withUserTotals(array $row): array
    {
        $row['generation_sandbox'] = ['known_cost_usd' => null, 'measured_count' => 0, 'unknown_count' => 0, 'unevaluated_count' => 0, 'trace_count' => 0];
        $row = $this->withOrganizationTotals($row);
        unset($row['generation_sandbox'], $row['ai_users_count']);

        return $row;
    }

    /**
     * Fragment SELECT des agregats embeddings — UNE definition pour la tranche
     * unitaire, la ventilation par utilisateur et par Organization.
     */
    private function embeddingSelect(): string
    {
        return implode(', ', [
            // Somme sans ELSE 0 : NULL quand rien n'est mesure.
            "SUM(CASE WHEN cost_status = 'known' THEN provider_cost END) as known_cost_usd",
            "COUNT(CASE WHEN cost_status = 'known' THEN 1 END) as measured_count",
            // Inconnu economique = appel REUSSI non mesurable ; l'echec a son
            // propre compteur.
            "COUNT(CASE WHEN status = 'success' AND cost_status = 'unknown' THEN 1 END) as unknown_count",
            'COUNT(*) as invocation_count',
            "COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_count",
        ]);
    }

    /**
     * @return array{known_cost_usd: ?float, measured_count: int, unknown_count: int, invocation_count: int, failed_count: int}
     */
    private function embeddingRow(?object $row): array
    {
        return [
            'known_cost_usd' => $row?->known_cost_usd !== null ? (float) $row->known_cost_usd : null,
            'measured_count' => (int) ($row?->measured_count ?? 0),
            'unknown_count' => (int) ($row?->unknown_count ?? 0),
            'invocation_count' => (int) ($row?->invocation_count ?? 0),
            'failed_count' => (int) ($row?->failed_count ?? 0),
        ];
    }

    /**
     * Bloc generation — delegue a l'autorite TASK-1219 pour que la meme
     * fenetre rende les memes chiffres ici et dans la console existante.
     *
     * @return array{known_cost_usd: ?float, measured_count: int, unknown_count: int, unevaluated_count: int, trace_count: int}
     */
    private function generationSlice(string $organizationId, CarbonImmutable $from, CarbonImmutable $to, ?string $userId = null): array
    {
        $summary = $this->generationAuthority->summary(
            $organizationId,
            new AiConsumptionFilters($from, $to, $userId),
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
        ?string $userId = null,
    ): array {
        $row = AiProviderInvocation::query()
            ->where('organization_id', $organizationId)
            ->when($userId !== null, static fn ($query) => $query->where('user_id', $userId))
            ->where('operation', AiProviderInvocation::OPERATION_EMBEDDING)
            ->when(
                $embeddingOperation !== null,
                static fn ($query) => $query->where('embedding_operation', $embeddingOperation),
                static fn ($query) => $query->whereNull('embedding_operation'),
            )
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->selectRaw($this->embeddingSelect())
            ->first();

        return $this->embeddingRow($row);
    }
}
