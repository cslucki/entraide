<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * TASK-1446 — AcquisitionJourney foundation (Growth V2 §2, MASTER Q74) :
 * une definition VERSIONNEE et TENANTEE d'un parcours d'acquisition.
 * Organization = Tenant : chaque ligne porte son organization_id, et rien ne
 * traverse. Une Journey ne decide pas du mode Shell (la politique de
 * l'Organization reste l'autorite), ne declare pas de sources publiques, ne
 * porte pas de CTA libre — elle nomme une intention de parcours.
 *
 * Cycle : `draft` (editable) -> `published` (IMMUABLE : nouvelle version pour
 * changer) -> `retired`. Une seule `published` par (organization, key).
 */
class AcquisitionJourney extends Model
{
    use HasUuids;

    public const STATE_DRAFT = 'draft';

    public const STATE_PUBLISHED = 'published';

    public const STATE_RETIRED = 'retired';

    public const STATES = [self::STATE_DRAFT, self::STATE_PUBLISHED, self::STATE_RETIRED];

    public const GOAL_ACCOUNT = 'account';

    /** Une INTENTION de parcours, pas une FK : aucun comportement tant que les Workshops n'existent pas (MASTER Q74). */
    public const GOAL_WORKSHOP_PARTICIPATION = 'workshop_participation';

    public const GOAL_CONTACT = 'contact';

    public const GOALS = [self::GOAL_ACCOUNT, self::GOAL_WORKSHOP_PARTICIPATION, self::GOAL_CONTACT];

    public const MAX_KEY_CHARS = 60;

    public const MAX_NAME_CHARS = 160;

    public const MAX_CAMPAIGN_CHARS = 100;

    protected $fillable = [
        'organization_id',
        'key',
        'name',
        'locale',
        'version',
        'state',
        'campaign',
        'conversion_goal',
        'usage_reference_surface_key',
        'created_by',
        'published_by',
        'published_at',
        'retired_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'published_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Une version publiee ne change plus silencieusement : seul son ETAT evolue (published -> retired).
        static::updating(function (self $journey): void {
            if ($journey->getOriginal('state') !== self::STATE_PUBLISHED) {
                return;
            }

            foreach (['organization_id', 'key', 'name', 'locale', 'version', 'campaign', 'conversion_goal', 'usage_reference_surface_key'] as $field) {
                if ($journey->isDirty($field)) {
                    throw new LogicException("A published acquisition journey is immutable ([{$field}]) : publish a new version instead.");
                }
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function scopeForOrganization(Builder $query, Organization|string $organization): Builder
    {
        return $query->where('organization_id', $organization instanceof Organization ? $organization->getKey() : $organization);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('state', self::STATE_PUBLISHED);
    }

    public function scopeForKey(Builder $query, string $key): Builder
    {
        return $query->where('key', $key);
    }

    public function isDraft(): bool
    {
        return $this->state === self::STATE_DRAFT;
    }

    public function isPublished(): bool
    {
        return $this->state === self::STATE_PUBLISHED;
    }

    public static function supportedLocales(): array
    {
        $locales = config('services.supported_locales', ['fr', 'en']);

        return is_array($locales) && $locales !== [] ? array_values($locales) : ['fr', 'en'];
    }
}
