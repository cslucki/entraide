<?php

namespace App\Services\Workshops;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workshop;
use App\Models\WorkshopSession;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * TASK-1451 — B4-A : les gestes sur une session d'atelier (Growth V3 §7,
 * MASTER Q78) : creation en brouillon, edition, publication, annulation — par
 * l'OrgAdmin de l'Organization du Workshop ou le SuperAdmin.
 *
 * Coherence tenant : la session herite de l'Organization de SON Workshop ;
 * un Workshop d'ailleurs n'existe pas. Dates saisies dans le fuseau IANA choisi,
 * stockees en UTC (meme convention que LoopEventService). Capacite informative.
 * Aucune inscription, aucun interet Guest, aucune meeting_url ici.
 */
final class WorkshopSessionService
{
    /**
     * @param  array{starts_at: string, ends_at?: string|null, timezone: string, capacity?: int|string|null, location?: string|null}  $attributes
     */
    public function create(Workshop $workshop, array $attributes, User $actor): WorkshopSession
    {
        $this->guardActor($workshop->organization, $actor);

        return WorkshopSession::query()->create($this->validated($attributes) + [
            'organization_id' => $workshop->organization_id,
            'workshop_id' => $workshop->getKey(),
            'status' => WorkshopSession::STATUS_DRAFT,
            'created_by' => $actor->getKey(),
        ]);
    }

    /**
     * @param  array{starts_at?: string, ends_at?: string|null, timezone?: string, capacity?: int|string|null, location?: string|null}  $attributes
     */
    public function update(WorkshopSession $session, array $attributes, User $actor): WorkshopSession
    {
        $this->guardActor($session->organization, $actor);
        if ($session->isCancelled()) {
            throw new LogicException('A cancelled session is not edited.');
        }

        $current = [
            'starts_at' => $session->localStartsAt()->format('Y-m-d H:i'),
            'ends_at' => $session->localEndsAt()?->format('Y-m-d H:i'),
            'timezone' => $session->timezone,
            'capacity' => $session->capacity,
            'location' => $session->location,
        ];

        $session->fill($this->validated($attributes + $current))->save();

        return $session;
    }

    public function publish(WorkshopSession $session, User $actor): WorkshopSession
    {
        $this->guardActor($session->organization, $actor);
        if (! $session->isDraft()) {
            throw new LogicException('Only a draft session can be published.');
        }

        $session->forceFill(['status' => WorkshopSession::STATUS_PUBLISHED, 'published_at' => now()])->save();

        return $session;
    }

    public function cancel(WorkshopSession $session, User $actor): WorkshopSession
    {
        $this->guardActor($session->organization, $actor);
        if ($session->isCancelled()) {
            throw new LogicException('This session is already cancelled.');
        }

        $session->forceFill(['status' => WorkshopSession::STATUS_CANCELLED, 'cancelled_at' => now()])->save();

        return $session;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{starts_at: CarbonImmutable, ends_at: CarbonImmutable|null, timezone: string, capacity: int|null, location: string|null}
     */
    private function validated(array $attributes): array
    {
        $timezone = trim((string) ($attributes['timezone'] ?? ''));
        if (! WorkshopSession::isValidTimezone($timezone)) {
            throw new InvalidArgumentException('A session needs a valid IANA timezone.');
        }

        $startsAt = $this->toUtc($attributes['starts_at'] ?? null, $timezone);
        if ($startsAt === null) {
            throw new InvalidArgumentException('A session needs a start date and time.');
        }
        $endsAt = $this->toUtc($attributes['ends_at'] ?? null, $timezone);
        if ($endsAt !== null && $endsAt->lessThanOrEqualTo($startsAt)) {
            throw new InvalidArgumentException('A session ends after it starts.');
        }

        $capacity = $attributes['capacity'] ?? null;
        $capacity = $capacity === null || $capacity === '' ? null : (int) $capacity;
        if ($capacity !== null && ($capacity < 1 || $capacity > WorkshopSession::MAX_CAPACITY)) {
            throw new InvalidArgumentException('A session capacity is 1 to '.WorkshopSession::MAX_CAPACITY.'.');
        }

        $location = trim((string) ($attributes['location'] ?? ''));

        return [
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'timezone' => $timezone,
            'capacity' => $capacity,
            'location' => $location === '' ? null : mb_substr($location, 0, WorkshopSession::MAX_LOCATION_CHARS),
        ];
    }

    /** Une date saisie dans le fuseau de la session → UTC ; vide = null ; illisible = faute. */
    private function toUtc(mixed $value, string $timezone): ?CarbonImmutable
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value, $timezone)->utc();
        } catch (Throwable) {
            throw new InvalidArgumentException('Unreadable session date.');
        }
    }

    private function guardActor(?Organization $organization, User $actor): void
    {
        if ($organization === null) {
            throw new AuthorizationException('A session belongs to an Organization.');
        }
        if ($actor->is_admin) {
            return;
        }
        if ((string) $organization->admin_id !== (string) $actor->getKey() || (string) $actor->organization_id !== (string) $organization->getKey()) {
            throw new AuthorizationException('Only the administrator of this Organization can write its workshop sessions.');
        }
    }
}
