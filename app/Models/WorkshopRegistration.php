<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * TASK-1453 — L'inscription REELLE d'un User verifie a une session d'atelier
 * (Growth V3 §7/§10/§11, MASTER Q78).
 *
 * Participation confirmee = compte reel + email Verified + geste explicite.
 * Une ligne par (session, User). La provenance Guest (visiteur rattache au
 * claim, Journey exacte) est reprise ici pour que l'attribution survive
 * jusqu'au CRM. Statuts : registered | cancelled.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $workshop_id
 * @property string $workshop_session_id
 * @property string $user_id
 * @property string|null $guest_visitor_id
 * @property string|null $acquisition_journey_id
 * @property string $status
 * @property Carbon $registered_at
 * @property Carbon|null $cancelled_at
 */
class WorkshopRegistration extends Model
{
    use HasUuids;

    public const STATUS_REGISTERED = 'registered';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_REGISTERED, self::STATUS_CANCELLED];

    protected $fillable = [
        'organization_id',
        'workshop_id',
        'workshop_session_id',
        'user_id',
        'guest_visitor_id',
        'acquisition_journey_id',
        'status',
        'registered_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'registered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function scopeForOrganization(Builder $query, Organization|string $organization): Builder
    {
        return $query->where('organization_id', $organization instanceof Organization ? $organization->getKey() : $organization);
    }

    public function scopeRegistered(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_REGISTERED);
    }

    public function isRegistered(): bool
    {
        return $this->status === self::STATUS_REGISTERED;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function workshop(): BelongsTo
    {
        return $this->belongsTo(Workshop::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(WorkshopSession::class, 'workshop_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(GuestVisitor::class, 'guest_visitor_id');
    }

    public function journey(): BelongsTo
    {
        return $this->belongsTo(AcquisitionJourney::class, 'acquisition_journey_id');
    }
}
