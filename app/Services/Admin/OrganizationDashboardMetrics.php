<?php

namespace App\Services\Admin;

use App\Models\AiInteraction;
use App\Models\LoginLog;
use App\Models\Loop;
use App\Models\LoopInvitation;
use App\Models\Organization;
use App\Models\Report;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\WorkshopSession;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Services\Ai\DTO\AiConsumptionFilters;
use App\Services\Ai\OrganizationAiConsumption;
use App\Services\GuestShell\GuestShellUsageService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * TASK-1504 — ce que le tableau de bord d'une Organization AFFICHE, calcule
 * a un seul endroit.
 *
 * Audit TASK-1501 : le dashboard comptait quatre choses et n'offrait aucune
 * action — « il compte, il ne dit pas quoi faire ». Decision Cyril (10/09) :
 * y mettre la depense IA, les visiteurs du Shell Welcome, les connexions
 * (jour / semaine / mois), qui se connecte le plus, les interactions IA.
 *
 * Aucun de ces chiffres n'est recalcule ici quand une autorite existe deja :
 * la depense vient de `OrganizationAiConsumption::summary` (la meme que la
 * page Consommation IA), l'usage du Shell de `GuestShellUsageService` (la
 * meme que le cockpit). Les compteurs simples lisent leurs tables avec les
 * MEMES bornes que les pages vers lesquelles chaque tuile envoie — sinon la
 * tuile et sa page se contrediraient.
 *
 * Le tenant est EXPLICITE : les modeles portant `BelongsToOrganizationScope`
 * sont lus hors du scope ambiant, avec `organization_id` en clair. Appele hors
 * d'une requete HTTP (test, commande), le scope ambiant filtrait tout a zero.
 *
 * Les fenetres sont calculees UNE fois, sur `now()` immuable : un tableau de
 * bord ouvert a 23h59 ne doit pas melanger deux jours.
 */
final class OrganizationDashboardMetrics
{
    public function __construct(
        private readonly OrganizationAiConsumption $consumption,
        private readonly GuestShellUsageService $guestShell,
    ) {}

    /**
     * @return array{
     *   counts: array{users: int, loops: int, services: int, requests: int},
     *   attention: array{open_requests: int, pending_invitations: int, pending_reports: int, upcoming_sessions: int, total: int},
     *   ai: array{spend_usd: ?float, spend_unknown: int, interactions: int},
     *   shell: array{visitors: int, conversations: int, accounts_claimed: int},
     *   logins: array{today: int, week: int, month: int},
     *   top_logins: Collection<int, array{user: User, logins: int, last_login_at: CarbonImmutable}>,
     *   activity: Collection<int, array{kind: string, at: CarbonImmutable, user: ?User, title: string, url: ?string}>,
     *   month_label: string
     * }
     */
    public function for(Organization $organization): array
    {
        $orgId = (string) $organization->getKey();
        $now = CarbonImmutable::now();
        $monthStart = $now->startOfMonth();

        return [
            'counts' => [
                'users' => User::where('organization_id', $orgId)->count(),
                'loops' => Loop::where('organization_id', $orgId)->count(),
                'services' => Service::withoutGlobalScope(BelongsToOrganizationScope::class)->where('organization_id', $orgId)->where('status', 'active')->count(),
                'requests' => ServiceRequest::withoutGlobalScope(BelongsToOrganizationScope::class)->where('organization_id', $orgId)->count(),
            ],
            'attention' => $this->attention($orgId, $now),
            'ai' => $this->ai($orgId, $monthStart, $now),
            'shell' => $this->shell($organization, $monthStart, $now),
            'logins' => [
                'today' => $this->loginsSince($orgId, $now->startOfDay()),
                'week' => $this->loginsSince($orgId, $now->subDays(7)),
                'month' => $this->loginsSince($orgId, $now->subDays(30)),
            ],
            'top_logins' => $this->topLogins($orgId, $now->subDays(30)),
            'activity' => $this->activity($orgId),
            'month_label' => $monthStart->translatedFormat('F Y'),
        ];
    }

    /** @return array{open_requests: int, pending_invitations: int, pending_reports: int, upcoming_sessions: int, total: int} */
    private function attention(string $orgId, CarbonImmutable $now): array
    {
        $items = [
            // La page Demandes filtre `status=open` : meme critere.
            'open_requests' => ServiceRequest::withoutGlobalScope(BelongsToOrganizationScope::class)->where('organization_id', $orgId)->where('status', 'open')->count(),
            // Une invitation expiree n'attend plus rien, meme si son statut dit encore « pending ».
            'pending_invitations' => LoopInvitation::where('organization_id', $orgId)
                ->where('status', LoopInvitation::STATUS_PENDING)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))
                ->count(),
            'pending_reports' => Report::where('organization_id', $orgId)->where('status', 'pending')->count(),
            'upcoming_sessions' => WorkshopSession::where('organization_id', $orgId)->published()->upcoming()->count(),
        ];

        return $items + ['total' => array_sum($items)];
    }

    /** @return array{spend_usd: ?float, spend_unknown: int, interactions: int} */
    private function ai(string $orgId, CarbonImmutable $monthStart, CarbonImmutable $now): array
    {
        $summary = $this->consumption->summary($orgId, AiConsumptionFilters::currentMonth());

        return [
            // NULL = aucune mesure ce mois ; ce n'est pas « 0,00 $ » (meme regle que la page Consommation IA).
            'spend_usd' => $summary['known_cost_usd'] !== null ? (float) $summary['known_cost_usd'] : null,
            'spend_unknown' => (int) ($summary['unknown_count'] ?? 0),
            'interactions' => AiInteraction::where('organization_id', $orgId)
                ->where('created_at', '>=', $monthStart)
                ->where('created_at', '<', $now->addSecond())
                ->count(),
        ];
    }

    /** @return array{visitors: int, conversations: int, accounts_claimed: int} */
    private function shell(Organization $organization, CarbonImmutable $monthStart, CarbonImmutable $now): array
    {
        $usage = $this->guestShell->organizationUsage($organization, $monthStart, $monthStart->addMonth());

        return [
            'visitors' => (int) ($usage['visitors'] ?? 0),
            'conversations' => (int) ($usage['conversations'] ?? 0),
            'accounts_claimed' => (int) ($usage['accounts_claimed'] ?? 0),
        ];
    }

    private function loginsSince(string $orgId, CarbonImmutable $since): int
    {
        return LoginLog::where('organization_id', $orgId)->where('created_at', '>=', $since)->count();
    }

    /** @return Collection<int, array{user: User, logins: int, last_login_at: CarbonImmutable}> */
    private function topLogins(string $orgId, CarbonImmutable $since): Collection
    {
        $rows = LoginLog::query()
            ->selectRaw('user_id, COUNT(*) as logins, MAX(created_at) as last_login_at')
            ->where('organization_id', $orgId)
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
                'logins' => (int) $row->logins,
                'last_login_at' => CarbonImmutable::parse($row->last_login_at),
            ])
            ->values();
    }

    /**
     * Les derniers membres et les dernieres demandes, meles par date : ce qui
     * s'est passe, pas deux listes qui se tournent le dos.
     *
     * @return Collection<int, array{kind: string, at: CarbonImmutable, user: ?User, title: string, url: ?string}>
     */
    private function activity(string $orgId): Collection
    {
        $members = User::where('organization_id', $orgId)->latest()->limit(5)->get()
            ->map(fn (User $u) => ['kind' => 'member', 'at' => CarbonImmutable::instance($u->created_at), 'user' => $u, 'title' => $u->full_name, 'url' => null]);

        $requests = ServiceRequest::withoutGlobalScope(BelongsToOrganizationScope::class)->where('organization_id', $orgId)->with('user')->latest()->limit(5)->get()
            ->map(fn (ServiceRequest $r) => ['kind' => 'request', 'at' => CarbonImmutable::instance($r->created_at), 'user' => $r->user, 'title' => (string) $r->title, 'url' => route('requests.show', $r)]);

        return $members->concat($requests)->sortByDesc('at')->take(8)->values();
    }
}
