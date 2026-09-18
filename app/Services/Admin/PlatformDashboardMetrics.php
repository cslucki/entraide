<?php

namespace App\Services\Admin;

use App\Models\AiInteraction;
use App\Models\LoginLog;
use App\Models\Organization;
use App\Models\Report;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\OrganizationAiConsumption;
use App\Services\GuestShell\GuestShellPolicyService;
use App\Services\GuestShell\GuestShellUsageService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * TASK-1507 — le tableau de bord SuperAdmin, toutes Organizations confondues.
 *
 * Cyril : « pour le panneau superadmin, ce sera tout org confondues » — memes
 * indicateurs que le tableau de bord d'Organisation (TASK-1504) : depense IA,
 * internautes du Shell Welcome, connexions jour / semaine / mois, comptes qui
 * se connectent le plus, interactions IA.
 *
 * ## Pourquoi ce n'est PAS le service d'Organisation avec un parametre en plus
 *
 * `OrganizationDashboardMetrics` filtre chaque requete sur un `organization_id`.
 * Ici il n'y en a aucun : on lit la plateforme entiere. Les modeles PORTES par
 * `BelongsToOrganizationScope` (`Service`, `Transaction`) sont donc lus
 * `withoutGlobalScope` sans filtre de remplacement — hors requete HTTP, le
 * scope ambiant ramenerait tout a zero, et avec lui on ne verrait qu'un tenant
 * arbitraire. `AiInteraction`, `LoginLog` et `Report` ne le portent PAS :
 * l'appel y serait mort, et laisserait croire a une protection inexistante.
 *
 * ## Ce que la depense IA veut dire ici
 *
 * `OrganizationAiConsumption::byOrganization()` ventile par tenant et sort les
 * traces sans Organization sous la cle `''` : elles ne sont rattachables a
 * personne. Le total plateforme les COMPTE — elles ont coute — mais le
 * classement par Organization ne peut pas les attribuer, et le dit.
 *
 * NULL n'est pas zero : aucune mesure ce mois se rend « — », jamais « 0,00 $ »
 * (meme regle que la page Consommation IA et que TASK-1504).
 *
 * Les fenetres sont calculees UNE fois sur `now()` immuable.
 */
final class PlatformDashboardMetrics
{
    public function __construct(
        private readonly OrganizationAiConsumption $consumption,
        private readonly GuestShellUsageService $guestShell,
        private readonly GuestShellPolicyService $policies,
    ) {}

    /**
     * @return array{
     *   counts: array{organizations: int, organizations_active: int, users: int, banned: int, services: int, transactions: int, transactions_pending: int},
     *   attention: array{pending_reports: int, banned_users: int, inactive_organizations: int, total: int},
     *   ai: array{spend_usd: ?float, spend_unknown: int, spend_unattributed_usd: ?float, interactions: int},
     *   shell: array{visitors: int, conversations: int, accounts_claimed: int, organizations_active: int},
     *   logins: array{today: int, week: int, month: int},
     *   top_logins: Collection<int, array{user: User, organization: ?Organization, logins: int, last_login_at: CarbonImmutable}>,
     *   top_organizations: Collection<int, array{organization: Organization, spend_usd: ?float, interactions: int}>,
     *   month_label: string
     * }
     */
    public function get(): array
    {
        $now = CarbonImmutable::now();
        $monthStart = $now->startOfMonth();

        $organizations = Organization::query()->get()->keyBy(fn (Organization $o) => (string) $o->getKey());
        $byOrganization = $this->consumption->byOrganization($monthStart, $monthStart->addMonth());

        return [
            'counts' => [
                'organizations' => $organizations->count(),
                'organizations_active' => $organizations->where('is_active', true)->count(),
                'users' => User::count(),
                'banned' => User::whereNotNull('banned_at')->count(),
                'services' => Service::withoutGlobalScope(BelongsToOrganizationScope::class)->where('status', 'active')->count(),
                'transactions' => Transaction::withoutGlobalScope(BelongsToOrganizationScope::class)->where('status', 'completed')->count(),
                // « 0 finalise » seul cache qu'il y en a six EN ATTENTE.
                'transactions_pending' => Transaction::withoutGlobalScope(BelongsToOrganizationScope::class)->where('status', 'pending')->count(),
            ],
            'attention' => $this->attention($organizations),
            'ai' => $this->ai($byOrganization, $monthStart, $now),
            'shell' => $this->shell($monthStart),
            'logins' => [
                'today' => $this->loginsSince($now->startOfDay()),
                'week' => $this->loginsSince($now->subDays(7)),
                'month' => $this->loginsSince($now->subDays(30)),
            ],
            'top_logins' => $this->topLogins($now->subDays(30), $organizations),
            'top_organizations' => $this->topOrganizations($byOrganization, $organizations, $monthStart, $now),
            'month_label' => $monthStart->translatedFormat('F Y'),
        ];
    }

    /**
     * @param  Collection<string, Organization>  $organizations
     * @return array{pending_reports: int, banned_users: int, inactive_organizations: int, total: int}
     */
    private function attention(Collection $organizations): array
    {
        $items = [
            'pending_reports' => Report::where('status', 'pending')->count(),
            'banned_users' => User::whereNotNull('banned_at')->count(),
            'inactive_organizations' => $organizations->where('is_active', false)->count(),
        ];

        return $items + ['total' => array_sum($items)];
    }

    /**
     * @param  array<string, array{known_cost_usd: ?float, unknown_count: int}>  $byOrganization
     * @return array{spend_usd: ?float, spend_unknown: int, spend_unattributed_usd: ?float, interactions: int}
     */
    private function ai(array $byOrganization, CarbonImmutable $monthStart, CarbonImmutable $now): array
    {
        $known = null;
        $unknown = 0;

        foreach ($byOrganization as $row) {
            if ($row['known_cost_usd'] !== null) {
                $known = ($known ?? 0.0) + (float) $row['known_cost_usd'];
            }
            $unknown += (int) ($row['unknown_count'] ?? 0);
        }

        // Les traces sans Organization : comptees dans le total, jamais
        // attribuees a un tenant. Rendues a part pour que l'ecart s'explique.
        $unattributed = isset($byOrganization['']) && $byOrganization['']['known_cost_usd'] !== null
            ? (float) $byOrganization['']['known_cost_usd']
            : null;

        return [
            'spend_usd' => $known,
            'spend_unknown' => $unknown,
            'spend_unattributed_usd' => $unattributed,
            'interactions' => AiInteraction::query()
                ->where('created_at', '>=', $monthStart)
                ->where('created_at', '<', $now->addSecond())
                ->count(),
        ];
    }

    /** @return array{visitors: int, conversations: int, accounts_claimed: int, organizations_active: int} */
    private function shell(CarbonImmutable $monthStart): array
    {
        $usage = $this->guestShell->platformUsage($monthStart, $monthStart->addMonth(), $this->policies);
        $totals = $usage['totals'];

        return [
            'visitors' => (int) ($totals['visitors'] ?? 0),
            'conversations' => (int) ($totals['conversations'] ?? 0),
            'accounts_claimed' => (int) ($totals['accounts_claimed'] ?? 0),
            'organizations_active' => (int) ($totals['organizations_active'] ?? 0),
        ];
    }

    private function loginsSince(CarbonImmutable $since): int
    {
        return LoginLog::where('created_at', '>=', $since)->count();
    }

    /**
     * @param  Collection<string, Organization>  $organizations
     * @return Collection<int, array{user: User, organization: ?Organization, logins: int, last_login_at: CarbonImmutable}>
     */
    private function topLogins(CarbonImmutable $since, Collection $organizations): Collection
    {
        $rows = LoginLog::query()
            ->selectRaw('user_id, COUNT(*) as logins, MAX(created_at) as last_login_at')
            ->where('created_at', '>=', $since)
            ->groupBy('user_id')
            ->orderByDesc('logins')
            ->orderByDesc('last_login_at')
            ->limit(5)
            ->get();

        $users = User::whereIn('id', $rows->pluck('user_id'))->get()->keyBy('id');

        return $rows
            ->filter(fn ($row) => $users->has($row->user_id))
            ->map(fn ($row) => [
                'user' => $users[$row->user_id],
                // Toutes organisations confondues : dire LAQUELLE, sinon un nom
                // seul ne situe rien.
                'organization' => $organizations->get((string) $users[$row->user_id]->organization_id),
                'logins' => (int) $row->logins,
                'last_login_at' => CarbonImmutable::parse($row->last_login_at),
            ])
            ->values();
    }

    /**
     * Les Organizations qui depensent le plus ce mois. La cle `''`
     * (traces non rattachables) n'est PAS une Organization : elle est exclue du
     * classement, et rendue a part par `ai.spend_unattributed_usd`.
     *
     * @param  array<string, array{known_cost_usd: ?float}>  $byOrganization
     * @param  Collection<string, Organization>  $organizations
     * @return Collection<int, array{organization: Organization, spend_usd: ?float, interactions: int}>
     */
    private function topOrganizations(array $byOrganization, Collection $organizations, CarbonImmutable $monthStart, CarbonImmutable $now): Collection
    {
        $interactions = AiInteraction::query()
            ->selectRaw('organization_id, COUNT(*) as total')
            ->where('created_at', '>=', $monthStart)
            ->where('created_at', '<', $now->addSecond())
            ->groupBy('organization_id')
            ->pluck('total', 'organization_id');

        return collect($byOrganization)
            ->filter(fn (array $row, string $id) => $id !== '' && $organizations->has($id) && $row['known_cost_usd'] !== null)
            ->map(fn (array $row, string $id) => [
                'organization' => $organizations->get($id),
                'spend_usd' => (float) $row['known_cost_usd'],
                'interactions' => (int) ($interactions[$id] ?? 0),
            ])
            ->sortByDesc('spend_usd')
            ->take(5)
            ->values();
    }
}
