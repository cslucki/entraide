<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-1652 — l'identite MANIFEST d'une entite runtime, dans une sandbox.
 *
 * Repond a une seule question : « quelle stable key represente cet objet ? ».
 *
 * A ne pas confondre avec {@see ScenarioPackEntity}, qui repond a une question
 * differente — « quelles lignes CE chargement a-t-il creees, et donc lesquelles
 * Reset et Remove peuvent-ils detruire ? ». Y ecrire les objets nes de
 * l'activite les rendrait destructibles par un Reset qui ne les a jamais crees.
 *
 * Ecrit UNIQUEMENT par
 * {@see \App\Support\ScenarioManager\Capture\ScenarioCaptureKeyRegistry}.
 *
 * `entity_family` est le nom de famille MANIFEST (`users`, `loops`,
 * `training.modules`…), pas le nom de la table runtime : c'est le langage du
 * Manifest qui fait autorite, et il survit a un renommage de table.
 */
class ScenarioCaptureKey extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'entity_family',
        'entity_id',
        'stable_key',
        'first_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
