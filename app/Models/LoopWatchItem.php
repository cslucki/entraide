<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * TASK-1879 — une veille publiee dans une Boucle.
 *
 * Date, categorie (facultative tant que la Boucle n'en a aucune active),
 * information, impact et source : tout est formule par un humain. Aucune IA.
 *
 * Suppression DOUCE. Pas de trait `HasOrganizationId` (voir LoopWatchCategory).
 * Ecrite exclusivement par LoopWatchService.
 */
class LoopWatchItem extends Model
{
    use HasUuids, SoftDeletes;

    public const INFORMATION_MAX = 5000;

    public const IMPACT_MAX = 5000;

    public const SOURCE_URL_MAX = 2048;

    protected $fillable = [
        'organization_id',
        'loop_id',
        'category_id',
        'observed_on',
        'information',
        'impact',
        'source_url',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'observed_on' => 'date',
        ];
    }

    public function loop(): BelongsTo
    {
        return $this->belongsTo(Loop::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(LoopWatchCategory::class, 'category_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
