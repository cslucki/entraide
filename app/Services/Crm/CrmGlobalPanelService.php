<?php

namespace App\Services\Crm;

use App\Models\CrmContact;
use App\Models\CrmContactEvent;
use App\Models\CrmStatus;
use App\Models\Organization;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * TASK-1427 — Panneau CRM du SuperAdmin : TOUT voir (decision Cyril, 07/09
 * 20h30 : « Le superadmin doit pouvoir tout voir »). Lecture transversale de
 * toutes les Organizations — contacts, echeances, derniers faits AVEC leur
 * contenu. Aucune ecriture ici : le SuperAdmin agit depuis le cockpit de
 * l'Organization (is_admin passe OrgAdminMiddleware), une seule
 * implementation des gardes.
 */
final class CrmGlobalPanelService
{
    public const PER_PAGE = 25;

    public const FACTS_LIMIT = 50;

    public const IDLE_DAYS = CrmDashboardService::IDLE_DAYS;

    public const DUE = ['today', 'overdue', 'week'];

    /** @return Collection<int, Organization> */
    public function organizations(): Collection
    {
        return Organization::query()->orderBy('name')->get(['id', 'name', 'slug']);
    }

    /** Les libelles de statut, toutes Organizations confondues (un statut est propre a chacune). */
    public function statusLabels(): Collection
    {
        return CrmStatus::query()->select('label')->distinct()->orderBy('label')->pluck('label');
    }

    /**
     * @param  array{search?: string, organization?: string, status?: string, due?: string, idle?: bool, contactable?: string}  $filters
     */
    public function contacts(array $filters, ?CarbonInterface $now = null): LengthAwarePaginator
    {
        $now = $now ?? now();
        $query = CrmContact::query()->with(['organization:id,name,slug', 'status']);

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $needle = '%'.mb_strtolower($search).'%';
            $digits = preg_replace('/\D+/', '', $search);
            $query->where(function (Builder $q) use ($needle, $digits) {
                $q->whereRaw('LOWER(first_name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(company) LIKE ?', [$needle]);
                if ($digits !== '') {
                    $q->orWhere('phone_normalized', 'like', '%'.$digits.'%');
                }
            });
        }

        if (($filters['organization'] ?? '') !== '') {
            $query->where('organization_id', $filters['organization']);
        }

        if (($filters['status'] ?? '') !== '') {
            $query->whereHas('status', fn (Builder $q) => $q->where('label', $filters['status']));
        }

        $day = $now->toDateString();
        match ($filters['due'] ?? '') {
            'today' => $query->whereDate('next_action_date', $day),
            'overdue' => $query->whereDate('next_action_date', '<', $day),
            'week' => $query->whereDate('next_action_date', '>=', $day)->whereDate('next_action_date', '<=', $now->copy()->endOfWeek()->toDateString()),
            default => null,
        };

        if (! empty($filters['idle'])) {
            $query->where(function (Builder $q) use ($now) {
                $q->whereNull('last_interaction_at')->orWhere('last_interaction_at', '<', $now->copy()->subDays(self::IDLE_DAYS));
            });
        }

        match ($filters['contactable'] ?? '') {
            'yes' => $query->whereNull('do_not_contact_at'),
            'no' => $query->whereNotNull('do_not_contact_at'),
            default => null,
        };

        return $query->orderByRaw('last_interaction_at IS NULL')
            ->orderByDesc('last_interaction_at')
            ->orderByDesc('created_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * @return array{today: Collection<int, CrmContact>, overdue: Collection<int, CrmContact>, week: Collection<int, CrmContact>}
     */
    public function today(?CarbonInterface $now = null): array
    {
        $now = $now ?? now();
        $day = $now->toDateString();
        $base = fn (): Builder => CrmContact::query()->with(['organization:id,name,slug', 'status']);
        $order = fn (Builder $q): Builder => $q->orderBy('next_action_date')->orderByRaw('next_action_time IS NULL')->orderBy('next_action_time')->orderBy('organization_id')->orderBy('last_name');

        return [
            'today' => $order($base()->whereDate('next_action_date', $day))->get(),
            'overdue' => $order($base()->whereDate('next_action_date', '<', $day))->get(),
            'week' => $order($base()->whereDate('next_action_date', '>', $day)->whereDate('next_action_date', '<=', $now->copy()->endOfWeek()->toDateString()))->get(),
        ];
    }

    /** @return Collection<int, CrmContactEvent> les derniers faits, toutes Organizations, avec contenu. */
    public function facts(int $limit = self::FACTS_LIMIT): Collection
    {
        return CrmContactEvent::query()
            ->with(['contact' => fn ($q) => $q->withTrashed(), 'author', 'organization:id,name,slug'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }
}
