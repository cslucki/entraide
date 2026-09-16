<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\DTO\AiConsumptionFilters;
use App\Services\Ai\OrganizationAiEconomicUsage;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * TASK-1586 — « Utilisation IA par utilisateur » (SuperAdmin) sur l'AUTORITE
 * economique canonique, la MEME que le releve Organization Admin
 * (`ai-consumption`), « Mes usages IA » et le cockpit plateforme :
 * `OrganizationAiEconomicUsage::byUser()`, Organization par Organization.
 *
 * Avant : somme brute de deux registres de traces historiques
 * (`ai_interactions` + `admin_ai_interactions`) qui se chevauchaient, et qui
 * ne voyaient ni embedding ni rerank. Ce total legacy est ABANDONNE pour le
 * reporting economique ; l'ecran est conserve.
 *
 * Ce que cet ecran rend, par utilisateur et par Organization :
 *   generation + embedding ingestion + embedding query (+ non declaree) + rerank,
 *   cout CONNU / appels au cout INCONNU / echecs, separes ; un inconnu ne
 *   devient jamais $0 ; aucun double comptage (une invocation = une ligne du
 *   ledger, une generation = une trace). Attribution : `user_id` de la trace,
 *   defense tenant `users.organization_id` — une trace non attribuable remonte
 *   sous « non attribuable », comptee, jamais repartie.
 *
 * Invariant : la ligne d'un utilisateur ici EST sa ligne dans le releve de
 * son Organization Admin sur la meme fenetre (meme methode, memes chiffres).
 *
 * Aucune ecriture, aucune modification du ledger, aucune migration.
 */
class AdminIaUsageByUserController extends Controller
{
    private const PER_PAGE = 50;

    private const SORTS = ['known_cost', 'unknown_count', 'total_count', 'user'];

    public function index(Request $request, OrganizationAiEconomicUsage $usage): View
    {
        [$from, $to] = $this->fenetre($request);

        $organizationId = $this->uuidOuNull($request->query('organization_id'));
        $organizations = Organization::query()->orderBy('name')->get(['id', 'name', 'slug']);
        $cibles = $organizationId !== null ? $organizations->where('id', $organizationId) : $organizations;

        $rows = [];

        foreach ($cibles as $organization) {
            foreach ($usage->byUser((string) $organization->id, $from, $to) as $row) {
                if (($row['total_count'] ?? 0) === 0 && ($row['rerank']['invocation_count'] ?? 0) === 0) {
                    continue;
                }
                $row['organization'] = $organization;
                $rows[] = $row;
            }
        }

        // Nom + email des utilisateurs attribues (une requete), pour la
        // recherche et l'affichage. Les non attribuables n'ont pas de nom.
        $userIds = array_values(array_unique(array_filter(array_column($rows, 'user_id'))));
        $users = $userIds === [] ? collect() : User::query()->whereIn('id', $userIds)->get(['id', 'name', 'email', 'organization_id'])->keyBy('id');

        foreach ($rows as &$row) {
            $row['user'] = $row['user_id'] !== null ? $users->get($row['user_id']) : null;
        }
        unset($row);

        $search = trim((string) $request->query('search', ''));

        if ($search !== '') {
            $aiguille = Str::lower($search);
            $rows = array_values(array_filter($rows, static function (array $row) use ($aiguille): bool {
                $user = $row['user'];

                return $user !== null && (str_contains(Str::lower((string) $user->name), $aiguille) || str_contains(Str::lower((string) $user->email), $aiguille));
            }));
        }

        $sort = in_array($request->query('sort'), self::SORTS, true) ? (string) $request->query('sort') : 'known_cost';
        $direction = $request->query('direction') === 'asc' ? 1 : -1;

        usort($rows, static function (array $a, array $b) use ($sort, $direction): int {
            // Non attribuable toujours en dernier.
            if (($a['user_id'] === null) !== ($b['user_id'] === null)) {
                return $a['user_id'] === null ? 1 : -1;
            }

            $cle = static fn (array $r): mixed => match ($sort) {
                'known_cost' => (float) ($r['total_known_cost_usd'] ?? -1),
                'unknown_count' => $r['total_unknown_count'],
                'total_count' => $r['total_count'] + $r['rerank']['invocation_count'],
                'user' => Str::lower((string) ($r['user']?->name ?? '')),
                default => 0,
            };

            return $direction * ($cle($a) <=> $cle($b));
        });

        $page = max(1, (int) $request->query('page', 1));
        $paginator = new LengthAwarePaginator(
            array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            count($rows),
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return view('admin.ia-usage-by-user.index', [
            'rows' => $paginator,
            'organizations' => $organizations,
            'from' => $from,
            'to' => $to,
            'filters' => $request->only(['organization_id', 'date_from', 'date_to', 'search', 'sort', 'direction']),
        ]);
    }

    /**
     * La fenetre `[from, to + 1 jour[` — la meme convention semi-ouverte que
     * `AiConsumptionFilters` ; defaut = mois courant (la fenetre de la garde).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function fenetre(Request $request): array
    {
        $mois = AiConsumptionFilters::currentMonth();
        $from = $this->date($request->query('date_from')) ?? $mois->from;
        $to = $this->date($request->query('date_to'))?->addDay() ?? $mois->to;

        if ($to <= $from) {
            return [$mois->from, $mois->to];
        }

        return [$from, $to];
    }

    private function date(mixed $valeur): ?CarbonImmutable
    {
        if (! is_string($valeur) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $valeur)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $valeur)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function uuidOuNull(mixed $valeur): ?string
    {
        return is_string($valeur) && Str::isUuid($valeur) ? $valeur : null;
    }
}
