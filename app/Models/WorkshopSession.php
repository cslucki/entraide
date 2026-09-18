<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * TASK-1451 — Une session d'un atelier (Growth V3 §7, MASTER Q78 B4-A).
 *
 * Coherence tenant : `organization_id` = celui du Workshop (garde de service).
 * Statuts : draft (invisible) → published (visible sur la page publique de
 * l'atelier, si l'atelier est publie) → cancelled (visible comme annulee ? non :
 * retiree de la page publique). Capacite INFORMATIVE : aucune place n'est
 * consommee ici (aucune inscription avant le flux canonique).
 *
 * Aucune `meeting_url` : le secret arrive avec son consommateur.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $workshop_id
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property string $timezone
 * @property int|null $capacity
 * @property string $status
 * @property string|null $location
 * @property string|null $created_by
 * @property Carbon|null $published_at
 * @property Carbon|null $cancelled_at
 */
class WorkshopSession extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_CANCELLED];

    public const MAX_LOCATION_CHARS = 255;

    public const MAX_CAPACITY = 10000;

    /** Le meme fuseau de dernier recours que les evenements de Loop. */
    public const FALLBACK_TIMEZONE = LoopEvent::FALLBACK_TIMEZONE;

    protected $fillable = [
        'organization_id',
        'workshop_id',
        'starts_at',
        'ends_at',
        'timezone',
        'capacity',
        'status',
        'location',
        'created_by',
        'published_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'capacity' => 'integer',
            'published_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public static function isValidTimezone(?string $timezone): bool
    {
        return LoopEvent::isValidTimezone($timezone);
    }

    /** @return list<string> */
    public static function timezoneOptions(?string $selected = null): array
    {
        return LoopEvent::timezoneOptions($selected);
    }

    public function scopeForOrganization(Builder $query, Organization|string $organization): Builder
    {
        return $query->where('organization_id', $organization instanceof Organization ? $organization->getKey() : $organization);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('ends_at', '>=', now())->orWhere(fn (Builder $q2) => $q2->whereNull('ends_at')->where('starts_at', '>=', now())));
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isPast(): bool
    {
        return ($this->ends_at ?? $this->starts_at)->isPast();
    }

    /** Le debut dans le fuseau de la session (affichage public). */
    public function localStartsAt(): Carbon
    {
        return $this->starts_at->copy()->setTimezone($this->timezone);
    }

    public function localEndsAt(): ?Carbon
    {
        return $this->ends_at?->copy()->setTimezone($this->timezone);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function workshop(): BelongsTo
    {
        return $this->belongsTo(Workshop::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
