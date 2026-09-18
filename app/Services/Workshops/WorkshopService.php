<?php

namespace App\Services\Workshops;

use App\Models\AcquisitionJourney;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workshop;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * TASK-1450 — Les gestes sur un Workshop (Growth V3 §7/§13) : creation en
 * brouillon, edition, publication, retrait — par l'OrgAdmin de SON
 * Organization ou le SuperAdmin (Organization explicite), jamais un membre.
 *
 * Tenant : la Journey rattachee est celle de la MEME Organization, en version
 * EXACTE (la ligne), sinon faute. Le slug est unique par Organization
 * (reutilisable ailleurs) et fige des la premiere publication (URL publique).
 * Aucune session, aucune inscription, aucune `meeting_url` ici (B4).
 */
final class WorkshopService
{
    /**
     * @param  array{title: string, slug?: string|null, promise?: string|null, description?: string|null, format: string, duration_minutes?: int|string|null, locale: string, acquisition_journey_id?: string|null}  $attributes
     */
    public function create(Organization $organization, array $attributes, User $actor): Workshop
    {
        $this->guardActor($organization, $actor);
        $data = $this->validated($organization, $attributes, null);

        return Workshop::query()->create($data + [
            'organization_id' => $organization->getKey(),
            'status' => Workshop::STATUS_DRAFT,
            'created_by' => $actor->getKey(),
        ]);
    }

    /**
     * @param  array{title?: string, slug?: string|null, promise?: string|null, description?: string|null, format?: string, duration_minutes?: int|string|null, locale?: string, acquisition_journey_id?: string|null}  $attributes
     */
    public function update(Workshop $workshop, array $attributes, User $actor): Workshop
    {
        $this->guardActor($workshop->organization, $actor);
        $data = $this->validated($workshop->organization, $attributes + $workshop->only(['title', 'slug', 'promise', 'description', 'format', 'duration_minutes', 'locale', 'acquisition_journey_id']), $workshop);

        if ($workshop->hasBeenPublished() && $data['slug'] !== $workshop->slug) {
            throw new LogicException('A published workshop keeps its public slug.');
        }

        $workshop->fill($data)->save();

        return $workshop;
    }

    public function publish(Workshop $workshop, User $actor): Workshop
    {
        $this->guardActor($workshop->organization, $actor);
        if ($workshop->isPublished()) {
            throw new LogicException('This workshop is already published.');
        }

        $workshop->forceFill([
            'status' => Workshop::STATUS_PUBLISHED,
            'published_by' => $actor->getKey(),
            'published_at' => now(),
            'retired_at' => null,
        ])->save();

        return $workshop;
    }

    public function retire(Workshop $workshop, User $actor): Workshop
    {
        $this->guardActor($workshop->organization, $actor);
        if (! $workshop->isPublished()) {
            throw new LogicException('Only a published workshop can be retired.');
        }

        $workshop->forceFill(['status' => Workshop::STATUS_RETIRED, 'retired_at' => now()])->save();

        return $workshop;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{title: string, slug: string, promise: string|null, description: string|null, format: string, duration_minutes: int|null, locale: string, acquisition_journey_id: string|null}
     */
    private function validated(Organization $organization, array $attributes, ?Workshop $current): array
    {
        $title = trim((string) ($attributes['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > Workshop::MAX_TITLE_CHARS) {
            throw new InvalidArgumentException('A workshop needs a title of at most '.Workshop::MAX_TITLE_CHARS.' characters.');
        }

        $slug = trim((string) ($attributes['slug'] ?? ''));
        $slug = $slug === '' ? Str::slug($title) : Str::slug($slug);
        $slug = mb_substr($slug, 0, Workshop::MAX_SLUG_CHARS);
        if (! Workshop::isValidSlug($slug)) {
            throw new InvalidArgumentException('A workshop slug is 3 to 80 lowercase characters, digits or dashes.');
        }
        $taken = Workshop::query()->forOrganization($organization)->where('slug', $slug)->when($current !== null, fn ($q) => $q->whereKeyNot($current->getKey()))->exists();
        if ($taken) {
            throw new InvalidArgumentException("The slug [{$slug}] is already used by another workshop of this organization.");
        }

        $format = (string) ($attributes['format'] ?? '');
        if (! in_array($format, Workshop::FORMATS, true)) {
            throw new InvalidArgumentException('A workshop format is in_person, online or hybrid.');
        }

        $locale = (string) ($attributes['locale'] ?? '');
        if (! in_array($locale, Workshop::supportedLocales(), true)) {
            throw new InvalidArgumentException('Unsupported workshop locale.');
        }

        $duration = $attributes['duration_minutes'] ?? null;
        $duration = $duration === null || $duration === '' ? null : (int) $duration;
        if ($duration !== null && ($duration < 1 || $duration > Workshop::MAX_DURATION_MINUTES)) {
            throw new InvalidArgumentException('A workshop duration is 1 to '.Workshop::MAX_DURATION_MINUTES.' minutes.');
        }

        $journeyId = $attributes['acquisition_journey_id'] ?? null;
        $journeyId = $journeyId === null || $journeyId === '' ? null : (string) $journeyId;
        if ($journeyId !== null) {
            $journey = AcquisitionJourney::query()->forOrganization($organization)->whereKey($journeyId)->first();
            if ($journey === null) {
                // Une Journey d'ailleurs, ou inconnue : n'existe pas pour cette Organization.
                throw new InvalidArgumentException('Unknown acquisition journey for this organization.');
            }
        }

        $promise = trim((string) ($attributes['promise'] ?? ''));
        $description = trim((string) ($attributes['description'] ?? ''));

        return [
            'title' => $title,
            'slug' => $slug,
            'promise' => $promise === '' ? null : mb_substr($promise, 0, Workshop::MAX_PROMISE_CHARS),
            'description' => $description === '' ? null : mb_substr($description, 0, Workshop::MAX_DESCRIPTION_CHARS),
            'format' => $format,
            'duration_minutes' => $duration,
            'locale' => $locale,
            'acquisition_journey_id' => $journeyId,
        ];
    }

    private function guardActor(?Organization $organization, User $actor): void
    {
        if ($organization === null) {
            throw new AuthorizationException('A workshop belongs to an Organization.');
        }
        if ($actor->is_admin) {
            return;
        }
        if ((string) $organization->admin_id !== (string) $actor->getKey() || (string) $actor->organization_id !== (string) $organization->getKey()) {
            throw new AuthorizationException('Only the administrator of this Organization can write its workshops.');
        }
    }
}
