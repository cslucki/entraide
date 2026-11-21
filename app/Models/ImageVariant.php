<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-1873 — une variante optimisee d'une image televersee.
 *
 * Volontairement SANS `HasOrganizationId` : ce registre est lu par la console
 * SuperAdmin a travers toutes les Organizations, et par le resolveur
 * d'affichage, qui sert des avatars sans contexte de tenant. Le filtrage par
 * Organization est explicite la ou il est demande.
 *
 * @property string $id
 * @property string|null $organization_id
 * @property string $surface
 * @property string $disk
 * @property string $source_path
 * @property string $preset
 * @property int $preset_version
 * @property string $status
 * @property string|null $path
 * @property int|null $width
 * @property int|null $height
 * @property int|null $bytes
 * @property string|null $format
 * @property int|null $source_bytes
 * @property string|null $source_hash
 * @property string|null $error
 * @property int $attempts
 */
class ImageVariant extends Model
{
    use HasUuids;

    public const STATUS_READY = 'ready';

    /** La source n'a pas pu etre traitee (decodage, ecriture). Reessayable. */
    public const STATUS_FAILED = 'failed';

    /** Ecartee volontairement (trop grande, format non traite). L'original reste servi. */
    public const STATUS_SKIPPED = 'skipped';

    /** La source n'existe plus sur le disque. */
    public const STATUS_MISSING = 'missing';

    public const STATUSES = [self::STATUS_READY, self::STATUS_FAILED, self::STATUS_SKIPPED, self::STATUS_MISSING];

    protected $fillable = [
        'organization_id', 'surface', 'disk', 'source_path', 'preset', 'preset_version',
        'status', 'path', 'width', 'height', 'bytes', 'format', 'source_bytes', 'source_hash',
        'error', 'attempts', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'preset_version' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'bytes' => 'integer',
            'source_bytes' => 'integer',
            'attempts' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
