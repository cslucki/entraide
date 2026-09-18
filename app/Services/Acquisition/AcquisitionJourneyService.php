<?php

namespace App\Services\Acquisition;

use App\Models\AcquisitionJourney;
use App\Models\Organization;
use App\Models\UsageReference;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * TASK-1446 — les SEULES ecritures d'une AcquisitionJourney (MASTER Q74) :
 * brouillon, edition de brouillon, publication (la precedente publiee de la
 * meme cle passe `retired`), retrait. L'auteur metier est l'OrgAdmin de
 * CETTE Organization ; le SuperAdmin agit transversalement, Organization
 * explicite. Un membre simple n'ecrit jamais. Une Journey etrangere = 404
 * fail-closed en amont, jamais une recherche globale exposee.
 */
final class AcquisitionJourneyService
{
    /**
     * @param  array{key: string, name: string, locale: string, conversion_goal: string, campaign?: string|null, usage_reference_surface_key?: string|null}  $attributes
     */
    public function createDraft(Organization $organization, array $attributes, User $actor): AcquisitionJourney
    {
        $this->guardActor($organization, $actor);
        $key = $this->key((string) ($attributes['key'] ?? ''), (string) ($attributes['name'] ?? ''));
        $data = $this->validated($attributes);

        return DB::transaction(function () use ($organization, $key, $data, $actor): AcquisitionJourney {
            AcquisitionJourney::query()->forOrganization($organization)->forKey($key)->lockForUpdate()->get();
            $next = ((int) AcquisitionJourney::query()->forOrganization($organization)->forKey($key)->max('version')) + 1;

            return AcquisitionJourney::query()->create($data + [
                'organization_id' => $organization->getKey(),
                'key' => $key,
                'version' => $next,
                'state' => AcquisitionJourney::STATE_DRAFT,
                'created_by' => $actor->getKey(),
            ]);
        });
    }

    /**
     * @param  array{name: string, locale: string, conversion_goal: string, campaign?: string|null, usage_reference_surface_key?: string|null}  $attributes
     */
    public function updateDraft(AcquisitionJourney $journey, array $attributes, User $actor): AcquisitionJourney
    {
        $this->guardActor($journey->organization, $actor);
        if (! $journey->isDraft()) {
            throw new LogicException('Only a draft acquisition journey can be edited : publish a new version instead.');
        }

        $journey->forceFill($this->validated($attributes))->save();

        return $journey;
    }

    public function publish(AcquisitionJourney $journey, User $actor): AcquisitionJourney
    {
        $this->guardActor($journey->organization, $actor);
        if (! $journey->isDraft()) {
            throw new LogicException('Only a draft acquisition journey can be published.');
        }

        return DB::transaction(function () use ($journey, $actor): AcquisitionJourney {
            $now = now();
            $current = AcquisitionJourney::query()
                ->forOrganization($journey->organization_id)
                ->forKey($journey->key)
                ->published()
                ->lockForUpdate()
                ->get();
            foreach ($current as $previous) {
                $previous->forceFill(['state' => AcquisitionJourney::STATE_RETIRED, 'retired_at' => $now])->save();
            }

            $journey->forceFill(['state' => AcquisitionJourney::STATE_PUBLISHED, 'published_by' => $actor->getKey(), 'published_at' => $now])->save();

            return $journey;
        });
    }

    public function retire(AcquisitionJourney $journey, User $actor): AcquisitionJourney
    {
        $this->guardActor($journey->organization, $actor);
        if (! $journey->isPublished()) {
            throw new LogicException('Only a published acquisition journey can be retired.');
        }

        $journey->forceFill(['state' => AcquisitionJourney::STATE_RETIRED, 'retired_at' => now()])->save();

        return $journey;
    }

    /** OrgAdmin de CETTE Organization, ou SuperAdmin — jamais un membre, jamais un OrgAdmin d'ailleurs. */
    private function guardActor(?Organization $organization, User $actor): void
    {
        if ($organization === null) {
            throw new AuthorizationException('An acquisition journey belongs to an Organization.');
        }
        if ($actor->is_admin) {
            return;
        }
        if ((string) $organization->admin_id !== (string) $actor->getKey() || (string) $actor->organization_id !== (string) $organization->getKey()) {
            throw new AuthorizationException('Only the administrator of this Organization can write its acquisition journeys.');
        }
    }

    private function key(string $key, string $name): string
    {
        $key = Str::slug($key !== '' ? $key : $name);
        if ($key === '' || mb_strlen($key) > AcquisitionJourney::MAX_KEY_CHARS) {
            throw new InvalidArgumentException('An acquisition journey requires a key (slug) of at most '.AcquisitionJourney::MAX_KEY_CHARS.' characters.');
        }

        return $key;
    }

    /** @return array{name: string, locale: string, conversion_goal: string, campaign: string|null, usage_reference_surface_key: string|null} */
    private function validated(array $attributes): array
    {
        $name = trim((string) ($attributes['name'] ?? ''));
        $locale = strtolower(trim((string) ($attributes['locale'] ?? '')));
        $goal = (string) ($attributes['conversion_goal'] ?? '');
        $campaign = trim((string) ($attributes['campaign'] ?? ''));
        $surface = trim((string) ($attributes['usage_reference_surface_key'] ?? ''));

        if ($name === '' || mb_strlen($name) > AcquisitionJourney::MAX_NAME_CHARS) {
            throw new InvalidArgumentException('An acquisition journey requires a name of at most '.AcquisitionJourney::MAX_NAME_CHARS.' characters.');
        }
        if (! in_array($locale, AcquisitionJourney::supportedLocales(), true)) {
            throw new InvalidArgumentException("Unsupported acquisition journey locale [{$locale}].");
        }
        if (! in_array($goal, AcquisitionJourney::GOALS, true)) {
            throw new InvalidArgumentException("Unknown acquisition journey conversion goal [{$goal}].");
        }
        if (mb_strlen($campaign) > AcquisitionJourney::MAX_CAMPAIGN_CHARS) {
            throw new InvalidArgumentException('An acquisition journey campaign is at most '.AcquisitionJourney::MAX_CAMPAIGN_CHARS.' characters.');
        }
        if ($surface !== '' && ! in_array($surface, UsageReference::SURFACES, true)) {
            throw new InvalidArgumentException("Unknown usage reference surface [{$surface}].");
        }

        return [
            'name' => $name,
            'locale' => $locale,
            'conversion_goal' => $goal,
            'campaign' => $campaign === '' ? null : $campaign,
            'usage_reference_surface_key' => $surface === '' ? null : $surface,
        ];
    }
}
