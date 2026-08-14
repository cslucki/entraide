<?php

namespace App\Models;

use App\Models\Traits\HasOrganizationId;
use Database\Factories\LoopManifestoSourceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Dossiers document linked as a source of a Loop's Manifesto.
 *
 * A link, never a copy: the file keeps living in Dossiers, under its own
 * permissions and its own owner. Detaching removes this row and nothing else.
 */
class LoopManifestoSource extends Model
{
    /** @use HasFactory<LoopManifestoSourceFactory> */
    use HasFactory, HasOrganizationId, HasUuids;

    protected $fillable = [
        'organization_id',
        'loop_id',
        'dossier_file_id',
        'added_by',
    ];

    public function loop(): BelongsTo
    {
        return $this->belongsTo(Loop::class);
    }

    public function dossierFile(): BelongsTo
    {
        return $this->belongsTo(DossierFile::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
