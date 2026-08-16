<?php

namespace App\Services\Ai;

use App\Services\Ai\DTO\AiConsumptionFilters;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read model de la console « Consommation IA » d'une Organization (TASK-1219).
 *
 * Repond a « qu'est-ce que mon IA a consomme, et qu'est-ce qui echappe a la
 * mesure ? ». Lecture seule : aucune ecriture, aucun appel provider.
 *
 * --------------------------------------------------------------------------
 * PERIMETRE : `ai_interactions`, et elle seule. Ce n'est pas un choix
 * arbitraire, c'est un ALIGNEMENT sur l'autorite qui decide deja.
 * --------------------------------------------------------------------------
 *
 * `AiEconomicGuard::authorize()` (TASK-1212) applique reellement le budget
 * mensuel d'une Organization en comptant exactement ceci :
 *
 *     ai_interactions WHERE organization_id = :org
 *       AND created_at dans le mois
 *       AND cost_unknown = false  -> SOMME cost_usd
 *       AND cost_unknown = true   -> COMPTE separe
 *
 * La console rend les memes chiffres, sur la meme table, avec la meme forme en
 * deux parts. Une console qui afficherait un total different de celui que la
 * garde applique serait pire que pas de console du tout.
 *
 * Pourquoi PAS les deux autres tables de trace : les fusionner exigerait une
 * heuristique, dans les deux sens et sans issue prouvable.
 *   - Sommer les trois SUR-COMPTE : `InlineMemberAgent` ecrit UNE invocation
 *     dans `admin_ai_interactions` ET `member_ai_profile_interactions`.
 *   - Dedupliquer par `correlation_id` SOUS-COMPTE : `AiCorrelation` documente
 *     qu'une correlation identifie une OPERATION metier, pas un appel LLM —
 *     une operation legitime produit plusieurs appels reels, donc plusieurs
 *     couts reels, sous une seule correlation.
 * Aucun identifiant d'invocation n'est partage entre les tables. On ne devine
 * donc pas : on s'aligne sur la garde, et on le dit a l'ecran.
 *
 * --------------------------------------------------------------------------
 * TROIS ETATS DE COUT, JAMAIS FUSIONNES (TASK-1132)
 * --------------------------------------------------------------------------
 *   cost_unknown = false -> cout connu, Y COMPRIS un 0 legitime (tarif nul) ;
 *   cost_unknown = true  -> cout non mesurable, `cost_usd` vaut NULL ;
 *   cost_unknown = null  -> statut jamais evalue (lignes anterieures a P1-2).
 *
 * Aucun `COALESCE(cost_usd, 0)` : ce serait rendre un tarif manquant
 * indiscernable d'un modele gratuit. Et `known_cost_usd` vaut `null` — pas
 * `0.0` — quand AUCUNE ligne mesuree n'entre dans le perimetre : afficher
 * « 0,00 $ » alors que rien n'est mesurable serait un mensonge de plus.
 *
 * --------------------------------------------------------------------------
 * CE QUI N'EST PAS AFFICHE, ET POURQUOI
 * --------------------------------------------------------------------------
 * - TOKENS : `ai_interactions.input_tokens` / `output_tokens` sont
 *   NOT NULL DEFAULT 0, et `AiUsage::inputTokensOrZero()` y ecrit 0 quand
 *   l'usage n'a PAS ete observe. Un 0 de tokens est donc indiscernable d'une
 *   mesure absente — exactement la dette que P1-2 a corrigee pour le cout et
 *   pas pour l'usage. Un total de tokens serait faussement certain : il n'est
 *   pas rendu.
 * - PROVENANCE DU COUT : `AiEconomicGuard::finalize()` retient soit le cout
 *   RAPPORTE par le provider, soit le cout CALCULE depuis `AiPricingCatalog`,
 *   et rien en base ne dit lequel. Le montant rendu est donc « cout
 *   fournisseur, rapporte ou estime », sans distinction possible.
 * - COUT PLATEFORME et PRIX CLIENT : n'existent pas dans le systeme (aucune
 *   marge, aucun prix, aucun credit). Ils ne sont pas inventes.
 * - SOURCE DE CREDENTIAL : aucune colonne ne relie une trace a la cle utilisee.
 *
 * --------------------------------------------------------------------------
 * TENANT
 * --------------------------------------------------------------------------
 * Chaque requete est bornee par `organization_id`. Les traces dont
 * `organization_id` est NULL ne sont RATTACHABLES a aucune Organization : elles
 * sont hors perimetre, jamais reparties ni devinees.
 */
class OrganizationAiConsumption
{
    /**
     * Compteurs de tete de page, en quatre parts qui ne se melangent pas.
     *
     * @return array{known_cost_usd: ?float, measured_count: int, unknown_count: int, unevaluated_count: int, trace_count: int, first_trace_at: ?string, last_trace_at: ?string}
     */
    public function summary(string $organizationId, AiConsumptionFilters $filters): array
    {
        $row = $this->baseQuery($organizationId, $filters)
            ->selectRaw($this->economicSelect())
            ->selectRaw('MIN(created_at) as first_trace_at')
            ->selectRaw('MAX(created_at) as last_trace_at')
            ->first();

        return [
            ...$this->economicRow($row),
            'first_trace_at' => $row?->first_trace_at,
            'last_trace_at' => $row?->last_trace_at,
        ];
    }

    /**
     * Ventilation par process (`AiProcess`, TASK-1131) : identifiant technique
     * stable, jamais un libelle traduit. La traduction se fait a l'affichage.
     *
     * @return array<int, array{key: ?string, known_cost_usd: ?float, measured_count: int, unknown_count: int, unevaluated_count: int, trace_count: int}>
     */
    public function byProcess(string $organizationId, AiConsumptionFilters $filters): array
    {
        return $this->groupedBy($organizationId, $filters, 'process');
    }

    /**
     * @return array<int, array{key: ?string, known_cost_usd: ?float, measured_count: int, unknown_count: int, unevaluated_count: int, trace_count: int}>
     */
    public function byModel(string $organizationId, AiConsumptionFilters $filters): array
    {
        return $this->groupedBy($organizationId, $filters, 'model');
    }

    /**
     * Ventilation par provider.
     *
     * `ai_interactions` n'a PAS de colonne `provider`, mais tous ses ecrivains
     * ecrivent `metadata->provider`. On lit donc une valeur REELLEMENT ecrite,
     * on ne la deduit pas du modele : un meme modele peut etre servi par
     * plusieurs providers, deduire serait inventer.
     *
     * Plusieurs ecrivains passent par `array_filter()` : quand le provider est
     * null la cle est absente du JSON. La ligne remonte alors avec `key = null`
     * et s'affiche « — » (inconnu), ce qui est exact.
     *
     * @return array<int, array{key: ?string, known_cost_usd: ?float, measured_count: int, unknown_count: int, unevaluated_count: int, trace_count: int}>
     */
    public function byProvider(string $organizationId, AiConsumptionFilters $filters): array
    {
        return $this->groupedBy($organizationId, $filters, $this->providerExpression(), raw: true);
    }

    /**
     * Ventilation par utilisateur.
     *
     * `ai_interactions.user_id` est NOT NULL : l'attribution est PROUVEE par la
     * colonne, jamais reconstruite. Aucune heuristique d'attribution.
     *
     * @return array<int, array{user_id: string, name: ?string, known_cost_usd: ?float, measured_count: int, unknown_count: int, unevaluated_count: int, trace_count: int}>
     */
    public function byUser(string $organizationId, AiConsumptionFilters $filters): array
    {
        $rows = $this->baseQuery($organizationId, $filters)
            ->leftJoin('users', 'users.id', '=', 'ai_interactions.user_id')
            ->selectRaw('ai_interactions.user_id as user_id')
            ->selectRaw('MAX(users.name) as name')
            ->selectRaw($this->economicSelect())
            ->groupBy('ai_interactions.user_id')
            ->get();

        return $rows
            ->map(fn (object $row): array => [
                'user_id' => (string) $row->user_id,
                'name' => $row->name !== null ? (string) $row->name : null,
                ...$this->economicRow($row),
            ])
            ->sortByDesc(fn (array $row): int => $row['trace_count'])
            ->values()
            ->all();
    }

    /**
     * Serie journaliere, pour lire une derive dans le temps.
     *
     * `DATE(created_at)` se compile a l'identique sur PostgreSQL et SQLite.
     *
     * @return array<int, array{day: string, known_cost_usd: ?float, measured_count: int, unknown_count: int, unevaluated_count: int, trace_count: int}>
     */
    public function byDay(string $organizationId, AiConsumptionFilters $filters): array
    {
        $rows = $this->baseQuery($organizationId, $filters)
            ->selectRaw('DATE(created_at) as day')
            ->selectRaw($this->economicSelect())
            ->groupByRaw('DATE(created_at)')
            ->orderByRaw('DATE(created_at)')
            ->get();

        return $rows
            ->map(fn (object $row): array => [
                'day' => (string) $row->day,
                ...$this->economicRow($row),
            ])
            ->all();
    }

    /**
     * Valeurs proposables dans les listes de filtres.
     *
     * Calculees sur la PERIODE seule, sans appliquer les filtres de dimension :
     * choisir un process ne doit pas faire disparaitre les modeles des autres
     * process de la liste, sinon on ne peut plus revenir en arriere.
     *
     * @return array{processes: array<int, string>, models: array<int, string>, providers: array<int, string>}
     */
    public function availableFilters(string $organizationId, AiConsumptionFilters $filters): array
    {
        $period = new AiConsumptionFilters($filters->from, $filters->to);

        return [
            'processes' => $this->distinctValues($organizationId, $period, 'process'),
            'models' => $this->distinctValues($organizationId, $period, 'model'),
            'providers' => $this->distinctValues($organizationId, $period, $this->providerExpression(), raw: true),
        ];
    }

    /**
     * Perimetre commun a toutes les lectures. Le tenant est pose ici, une seule
     * fois : aucune methode publique ne peut l'oublier.
     */
    private function baseQuery(string $organizationId, AiConsumptionFilters $filters): Builder
    {
        $query = DB::table('ai_interactions')
            ->where('ai_interactions.organization_id', $organizationId)
            ->where('ai_interactions.created_at', '>=', $filters->from)
            ->where('ai_interactions.created_at', '<', $filters->to);

        if ($filters->userId !== null) {
            $query->where('ai_interactions.user_id', $filters->userId);
        }

        if ($filters->process !== null) {
            $query->where('ai_interactions.process', $filters->process);
        }

        if ($filters->model !== null) {
            $query->where('ai_interactions.model', $filters->model);
        }

        if ($filters->provider !== null) {
            $query->whereRaw($this->providerExpression().' = ?', [$filters->provider]);
        }

        return $query;
    }

    /**
     * Les quatre agregats economiques, dans un fragment unique reutilise par
     * toutes les ventilations : une seule definition du « cout connu », pas une
     * variante par tableau.
     *
     * `SUM(CASE WHEN ... THEN cost_usd END)` est SANS `ELSE 0` a dessein : sans
     * ligne mesuree la somme vaut NULL, et l'interface rend « — » au lieu d'un
     * « 0,00 $ » qui se lirait comme une mesure.
     *
     * Les litteraux `true` / `false` sont compris tels quels par PostgreSQL et
     * par SQLite (>= 3.23) : pas de binding, donc pas d'ordre de binding a tenir
     * entre `selectRaw` et les `where`.
     */
    private function economicSelect(): string
    {
        return implode(', ', [
            'SUM(CASE WHEN cost_unknown = false THEN cost_usd END) as known_cost_usd',
            'COUNT(CASE WHEN cost_unknown = false THEN 1 END) as measured_count',
            'COUNT(CASE WHEN cost_unknown = true THEN 1 END) as unknown_count',
            'COUNT(CASE WHEN cost_unknown IS NULL THEN 1 END) as unevaluated_count',
            'COUNT(*) as trace_count',
        ]);
    }

    /**
     * @return array{known_cost_usd: ?float, measured_count: int, unknown_count: int, unevaluated_count: int, trace_count: int}
     */
    private function economicRow(?object $row): array
    {
        return [
            // NULL traverse intact : « aucune mesure » n'est pas « zero ».
            'known_cost_usd' => $row?->known_cost_usd !== null ? (float) $row->known_cost_usd : null,
            'measured_count' => (int) ($row?->measured_count ?? 0),
            'unknown_count' => (int) ($row?->unknown_count ?? 0),
            'unevaluated_count' => (int) ($row?->unevaluated_count ?? 0),
            'trace_count' => (int) ($row?->trace_count ?? 0),
        ];
    }

    /**
     * @return array<int, array{key: ?string, known_cost_usd: ?float, measured_count: int, unknown_count: int, unevaluated_count: int, trace_count: int}>
     */
    private function groupedBy(string $organizationId, AiConsumptionFilters $filters, string $column, bool $raw = false): array
    {
        $expression = $raw ? $column : 'ai_interactions.'.$column;

        $rows = $this->baseQuery($organizationId, $filters)
            ->selectRaw($expression.' as group_key')
            ->selectRaw($this->economicSelect())
            ->groupByRaw($expression)
            ->get();

        return $rows
            ->map(fn (object $row): array => [
                'key' => $row->group_key !== null ? (string) $row->group_key : null,
                ...$this->economicRow($row),
            ])
            ->sortByDesc(fn (array $row): int => $row['trace_count'])
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function distinctValues(string $organizationId, AiConsumptionFilters $filters, string $column, bool $raw = false): array
    {
        $expression = $raw ? $column : 'ai_interactions.'.$column;

        return $this->baseQuery($organizationId, $filters)
            ->selectRaw($expression.' as value')
            ->groupByRaw($expression)
            ->orderByRaw($expression)
            ->pluck('value')
            // Une valeur absente n'est pas une option de filtre : on ne propose
            // pas de filtrer sur « — ».
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->values()
            ->all();
    }

    /**
     * Chemin JSON compile par la grammaire de la connexion courante :
     * `"metadata"->>'provider'` sur PostgreSQL,
     * `json_extract("metadata", '$."provider"')` sur SQLite.
     */
    private function providerExpression(): string
    {
        return DB::getQueryGrammar()->wrap('metadata->provider');
    }
}
