<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-1873 — une IMAGE d'un lot (ses trois variantes comptent pour une).
 *
 * Cycle : `pending` -> `processing` -> `succeeded` | `skipped` | `missing` |
 * `failed`. Le passage `pending` -> `processing` est une mise a jour
 * CONDITIONNELLE (une seule ligne gagne) : deux jobs de la meme image — double
 * lancement, reprise apres pause — ne la traitent jamais deux fois.
 *
 * @property string $id
 * @property string $batch_id
 * @property string|null $organization_id
 * @property string $surface
 * @property string $disk
 * @property string $source_path
 * @property string $status
 * @property bool $force
 */
class ImageOptimizationBatchItem extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SUCCEEDED = 'succeeded';

    /** Ecartee : deja a jour, format non traite, image trop grande. */
    public const STATUS_SKIPPED = 'skipped';

    /** L'original n'existe pas sur le disque — distinct d'une panne technique. */
    public const STATUS_MISSING = 'missing';

    public const STATUS_FAILED = 'failed';

    public const DONE_STATUSES = [self::STATUS_SUCCEEDED, self::STATUS_SKIPPED, self::STATUS_MISSING, self::STATUS_FAILED];

    protected $fillable = [
        'batch_id', 'organization_id', 'surface', 'disk', 'source_path', 'status', 'force',
        'source_bytes', 'variant_bytes', 'served_bytes', 'error', 'attempts', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'force' => 'boolean',
            'source_bytes' => 'integer',
            'variant_bytes' => 'integer',
            'served_bytes' => 'integer',
            'attempts' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImageOptimizationBatch::class, 'batch_id');
    }
}
