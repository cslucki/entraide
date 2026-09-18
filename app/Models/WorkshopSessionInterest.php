<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * TASK-1452 — L'interet d'un visiteur pseudonyme pour une session d'atelier
 * (Growth V3 §10, MASTER Q78 B4-B).
 *
 * Interet != inscription : aucun User, aucune capacite consommee. Statuts :
 * selected (le visiteur a choisi cette session) | withdrawn (il s'est retire).
 * Une ligne par (session, visiteur) : re-selectionner une session retiree la
 * reactive, jamais une seconde ligne.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $workshop_id
 * @property string $workshop_session_id
 * @property string $guest_visitor_id
 * @property string $status
 * @property Carbon $selected_at
 * @property Carbon|null $withdrawn_at
 */
class WorkshopSessionInterest extends Model
{
    use HasUuids;

    public const STATUS_SELECTED = 'selected';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUSES = [self::STATUS_SELECTED, self::STATUS_WITHDRAWN];

    protected $fillable = [
        'organization_id',
        'workshop_id',
        'workshop_session_id',
        'guest_visitor_id',
        'status',
        'selected_at',
        'withdrawn_at',
    ];

    protected function casts(): array
    {
        return [
            'selected_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    public function scopeForOrganization(Builder $query, Organization|string $organization): Builder
    {
        return $query->where('organization_id', $organization instanceof Organization ? $organization->getKey() : $organization);
    }

    public function scopeSelected(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SELECTED);
    }

    public function isSelected(): bool
    {
        return $this->status === self::STATUS_SELECTED;
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

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(GuestVisitor::class, 'guest_visitor_id');
    }
}
