<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-1695 (point 5a) — un fichier partage avec une BOUCLE.
 *
 * Le destinataire est la Boucle : l'acces de chaque membre se resout a la
 * LECTURE (membre actif, policy), jamais fige a l'ecriture.
 *
 * Comme `DossierFileShare`, ce modele n'utilise PAS `HasOrganizationId` : ce
 * trait remplit `organization_id` depuis le CONTEXTE de la requete, or le
 * tenant d'une autorisation vient du FICHIER (lecon T1691).
 */
class DossierFileLoopShare extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'dossier_file_id',
        'loop_id',
        'shared_by',
    ];

    public function file(): BelongsTo
    {
        return $this->belongsTo(DossierFile::class, 'dossier_file_id');
    }

    public function loop(): BelongsTo
    {
        return $this->belongsTo(Loop::class);
    }

    public function sharedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_by');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
