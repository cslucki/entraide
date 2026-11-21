<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * TASK-1873 — un lot d'optimisation lance depuis la console ou la commande.
 *
 * Sans `HasOrganizationId` : outil SuperAdmin transverse ; l'Organization est
 * un FILTRE choisi (NULL = toutes).
 *
 * @property string $id
 * @property string|null $organization_id
 * @property string|null $surface
 * @property int $requested
 * @property int $total
 * @property string $status
 * @property bool $force
 */
class ImageOptimizationBatch extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';

    /** Un lot « actif » occupe la console : il ne peut y en avoir qu'un. */
    public const ACTIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_RUNNING, self::STATUS_PAUSED];

    /** TASK-1873 : un job par image sur la queue `images`. */
    public const MODE_QUEUE = 'queue';

    /** TASK-1875 : une image par requete HTTP, pilotee par le navigateur du SuperAdmin. */
    public const MODE_MANUAL = 'manual';

    protected $fillable = [
        'organization_id', 'surface', 'requested', 'total', 'status', 'mode', 'force',
        'started_at', 'paused_at', 'finished_at',
    ];

    protected $attributes = [
        'mode' => self::MODE_QUEUE,
    ];

    protected function casts(): array
    {
        return [
            'requested' => 'integer',
            'total' => 'integer',
            'force' => 'boolean',
            'started_at' => 'datetime',
            'paused_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ImageOptimizationBatchItem::class, 'batch_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function isPaused(): bool
    {
        return $this->status === self::STATUS_PAUSED;
    }

    public function isManual(): bool
    {
        return $this->mode === self::MODE_MANUAL;
    }
}
