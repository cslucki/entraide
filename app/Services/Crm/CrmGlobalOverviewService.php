<?php

namespace App\Services\Crm;

use App\Models\CrmContact;
use App\Models\CrmContactEvent;
use App\Models\CrmStatus;
use App\Models\Organization;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * TASK-1425 — CRM-15 : la vision GLOBALE du SuperAdmin, en AGREGATS
 * seulement (MASTER Q37, option a). Par Organization : nombres de Contacts,
 * joignables / non joignables, actions aujourd'hui, en retard, sans
 * interaction depuis 30 jours, repartition par statut, nombre de faits
 * recents et horodatage de derniere activite.
 *
 * Jamais le corps d'une note, l'objet d'un email ni le nom d'un Contact :
 * la vue plateforme ne lit pas les relations commerciales des tenants.
 * Lecture seule, 0 table, 0 colonne.
 */
final class CrmGlobalOverviewService
{
    public const IDLE_DAYS = CrmDashboardService::IDLE_DAYS;

    public const RECENT_DAYS = 7;

    /**
     * @return array{rows: Collection<int, array<string, mixed>>, totals: array<string, int>, idleDays: int, recentDays: int}
     */
    public function overview(?CarbonInterface $now = null): array
    {
        $now = $now ?? now();
        $day = $now->toDateString();

        $countBy = fn ($query): array => $query->selectRaw('organization_id, COUNT(*) AS n')
            ->groupBy('organization_id')
            ->pluck('n', 'organization_id')
            ->map(fn ($n) => (int) $n)
            ->all();

        $contacts = $countBy(CrmContact::query());
        $blocked = $countBy(CrmContact::query()->whereNotNull('do_not_contact_at'));
        $today = $countBy(CrmContact::query()->whereDate('next_action_date', $day));
        $overdue = $countBy(CrmContact::query()->whereDate('next_action_date', '<', $day));
        $idle = $countBy(CrmContact::query()->contactable()->where(function ($q) use ($now) {
            $q->whereNull('last_interaction_at')
                ->orWhere('last_interaction_at', '<', $now->copy()->subDays(self::IDLE_DAYS));
        }));
        $recentFacts = $countBy(CrmContactEvent::query()->where('occurred_at', '>=', $now->copy()->subDays(self::RECENT_DAYS)));
        $lastActivity = CrmContactEvent::query()->selectRaw('organization_id, MAX(occurred_at) AS last_at')
            ->groupBy('organization_id')
            ->pluck('last_at', 'organization_id')
            ->all();

        // Repartition par statut : un seul GROUP BY, puis les libelles de chaque Organization.
        $byStatusRaw = CrmContact::query()->whereNotNull('status_id')
            ->selectRaw('organization_id, status_id, COUNT(*) AS n')
            ->groupBy('organization_id', 'status_id')
            ->get();
        $statuses = CrmStatus::query()->whereIn('id', $byStatusRaw->pluck('status_id')->unique())->get()->keyBy('id');
        $byStatus = [];
        foreach ($byStatusRaw as $row) {
            $status = $statuses->get($row->status_id);
            if ($status) {
                $byStatus[$row->organization_id][] = ['label' => $status->label, 'color' => $status->color, 'sort_order' => $status->sort_order, 'is_active' => (bool) $status->is_active, 'count' => (int) $row->n];
            }
        }

        $rows = Organization::query()->orderBy('name')->get()->map(function (Organization $organization) use ($contacts, $blocked, $today, $overdue, $idle, $recentFacts, $lastActivity, $byStatus) {
            $id = $organization->id;
            $total = $contacts[$id] ?? 0;
            $statusRows = collect($byStatus[$id] ?? [])->sortBy('sort_order')->values();

            return [
                'organization' => $organization,
                'contacts' => $total,
                'blocked' => $blocked[$id] ?? 0,
                'contactable' => $total - ($blocked[$id] ?? 0),
                'today' => $today[$id] ?? 0,
                'overdue' => $overdue[$id] ?? 0,
                'idle' => $idle[$id] ?? 0,
                'recent_facts' => $recentFacts[$id] ?? 0,
                'last_activity_at' => isset($lastActivity[$id]) ? Carbon::parse($lastActivity[$id]) : null,
                'by_status' => $statusRows,
                'unassigned' => $total - $statusRows->sum('count'),
            ];
        });

        $totals = [];
        foreach (['contacts', 'contactable', 'blocked', 'today', 'overdue', 'idle', 'recent_facts'] as $key) {
            $totals[$key] = (int) $rows->sum($key);
        }
        $totals['organizations_with_contacts'] = $rows->where('contacts', '>', 0)->count();

        return ['rows' => $rows, 'totals' => $totals, 'idleDays' => self::IDLE_DAYS, 'recentDays' => self::RECENT_DAYS];
    }
}
