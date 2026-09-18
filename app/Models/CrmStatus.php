<?php

namespace App\Models;

use Database\Factories\CrmStatusFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * TASK-1414 — un statut du pipeline d'une Organization.
 *
 * Pas d'enum ferme : chaque Organization possede SES statuts, les renomme,
 * les reordonne, les desactive. Le pipeline initial est seme par
 * `CrmStatusService::ensureDefaultPipeline()` dans la locale de
 * l'Organization, puis les libelles sont de simples chaines qui lui
 * appartiennent.
 */
class CrmStatus extends Model
{
    /** @use HasFactory<CrmStatusFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'organization_id',
        'label',
        'sort_order',
        'is_active',
        'is_default',
        'color',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CrmContact::class, 'status_id');
    }

    public function scopeForOrganization(Builder $query, Organization|string $organization): Builder
    {
        $id = $organization instanceof Organization ? $organization->id : $organization;

        return $query->where('crm_statuses.organization_id', $id);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('crm_statuses.is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('crm_statuses.sort_order')->orderBy('crm_statuses.created_at');
    }
}
