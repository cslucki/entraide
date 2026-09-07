<?php

namespace App\Services\Crm;

use App\Models\CrmContact;
use App\Models\CrmContactEvent;
use App\Models\CrmStatus;
use App\Models\Organization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * TASK-1424 — CRM-14 : « Aujourd'hui », le read model de la page d'entree
 * de Relations. Lecture seule : aucune table, aucune colonne, aucune
 * ecriture. Une seule question : que dois-je faire aujourd'hui ?
 *
 * Bornes (jour civil de l'application, jamais d'heure inventee) :
 * - aujourd'hui  : next_action_date = J ;
 * - en retard    : next_action_date < J ;
 * - cette semaine: J < next_action_date <= dimanche (semaine ISO) — le jour
 *   meme n'y figure PAS, il est deja dans « aujourd'hui » ;
 * - sans contact : joignables, jamais contactes ou dernier contact
 *   > IDLE_DAYS ; un « ne pas contacter » n'a rien a reactiver.
 */
final class CrmDashboardService
{
    public const IDLE_DAYS = 30;

    public const LIST_LIMIT = 8;

    public const FACTS_LIMIT = 10;

    /**
     * @return array{
     *     day: CarbonInterface,
     *     today: Collection<int, CrmContact>,
     *     overdue: Collection<int, CrmContact>,
     *     week: Collection<int, CrmContact>,
     *     idle: Collection<int, CrmContact>,
     *     idleCount: int,
     *     idleDays: int,
     *     byStatus: Collection<int, CrmStatus>,
     *     unassignedCount: int,
     *     total: int,
     *     facts: Collection<int, CrmContactEvent>,
     *     listLimit: int,
     *     factsLimit: int,
     * }
     */
    public function today(Organization $organization, ?CarbonInterface $now = null): array
    {
        $now = $now ?? now();
        $day = $now->toDateString();

        $contacts = fn (): Builder => CrmContact::forOrganization($organization)->with('status');

        // whereDate : la colonne DATE s'ecrit « Y-m-d H:i:s » sur SQLite (cast
        // Eloquent) et « Y-m-d » sur PostgreSQL ; comparer des JOURS.
        $today = $this->byDue($contacts()->whereDate('next_action_date', $day))->get();
        $overdue = $this->byDue($contacts()->whereDate('next_action_date', '<', $day))->get();
        $week = $this->byDue(
            $contacts()->whereDate('next_action_date', '>', $day)
                ->whereDate('next_action_date', '<=', $now->copy()->endOfWeek()->toDateString())
        )->get();

        $idleQuery = $contacts()->contactable()->where(function (Builder $q) use ($now) {
            $q->whereNull('last_interaction_at')
                ->orWhere('last_interaction_at', '<', $now->copy()->subDays(self::IDLE_DAYS));
        });
        $idleCount = (clone $idleQuery)->count();
        // Jamais contactes d'abord (les plus negliges), puis du plus ancien.
        $idle = $idleQuery->orderByRaw('last_interaction_at IS NOT NULL')
            ->orderBy('last_interaction_at')
            ->orderBy('created_at')
            ->limit(self::LIST_LIMIT)
            ->get();

        // withCount respecte le SoftDeletes du Contact : un Contact supprime ne compte pas.
        $byStatus = CrmStatus::forOrganization($organization)->ordered()->withCount('contacts')->get();
        $unassignedCount = CrmContact::forOrganization($organization)->whereNull('status_id')->count();
        $total = CrmContact::forOrganization($organization)->count();

        $facts = CrmContactEvent::forOrganization($organization)
            ->with(['contact' => fn ($q) => $q->withTrashed(), 'author'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->limit(self::FACTS_LIMIT)
            ->get();

        return [
            'day' => $now,
            'today' => $today,
            'overdue' => $overdue,
            'week' => $week,
            'idle' => $idle,
            'idleCount' => $idleCount,
            'idleDays' => self::IDLE_DAYS,
            'byStatus' => $byStatus,
            'unassignedCount' => $unassignedCount,
            'total' => $total,
            'facts' => $facts,
            'listLimit' => self::LIST_LIMIT,
            'factsLimit' => self::FACTS_LIMIT,
        ];
    }

    /** Par echeance puis par heure (les sans-heure en dernier), puis par nom : un ordre stable. */
    private function byDue(Builder $query): Builder
    {
        return $query->orderBy('next_action_date')
            ->orderByRaw('next_action_time IS NULL')
            ->orderBy('next_action_time')
            ->orderBy('last_name')
            ->orderBy('first_name');
    }
}
