<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une entite reelle qui PARTICIPE a un chargement de scenario pack
 * (TASK-1240), avec le droit — ou non — pour ce chargement de la detruire
 * (TASK-1245, `ownership`).
 *
 * Ecrite UNIQUEMENT par `App\Support\ScenarioPacks\ScenarioPackEntityRegistrar`.
 * `organization_id` est duplique depuis le `ScenarioPackLoad` parent
 * (defense en profondeur : verification cross-tenant possible sans
 * jointure).
 *
 * `ownership` (TASK-1245) :
 *  - OWNERSHIP_CREATED : physiquement creee par ce chargement -> purge
 *    physique autorisee (`ScenarioPackEntityPurger`) ;
 *  - OWNERSHIP_REUSED  : preexistante, seulement referencee -> jamais
 *    supprimee, jamais modifiee par le pack ;
 *  - NULL              : inconnu (ligne anterieure a la migration T1245) ->
 *    aucune purge destructive, refus explicite.
 * Fixe a la premiere inscription, immuable ensuite.
 */
class ScenarioPackEntity extends Model
{
    use HasUuids;

    public const OWNERSHIP_CREATED = 'created';

    public const OWNERSHIP_REUSED = 'reused';

    protected $fillable = [
        'scenario_pack_load_id',
        'organization_id',
        'entity_type',
        'internal_key',
        'entity_model',
        'entity_id',
        'sequence',
        'ownership',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
        ];
    }

    public function scenarioPackLoad(): BelongsTo
    {
        return $this->belongsTo(ScenarioPackLoad::class, 'scenario_pack_load_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Ce chargement a physiquement cree l'entite : il a le droit de la detruire. */
    public function isOwnedByPack(): bool
    {
        return $this->ownership === self::OWNERSHIP_CREATED;
    }

    /** Ligne inscrite avant TASK-1245 : ownership jamais etabli, aucune purge possible. */
    public function hasUnknownOwnership(): bool
    {
        return $this->ownership === null;
    }

    /**
     * L'instance Eloquent reelle referencee par cette ligne de registre, ou
     * null si elle a deja ete supprimee (ex. cascade DB depuis un parent
     * retire avant elle).
     */
    public function resolveEntity(): ?Model
    {
        $modelClass = $this->entity_model;

        if (! class_exists($modelClass) || ! is_a($modelClass, Model::class, true)) {
            return null;
        }

        return $modelClass::query()->find($this->entity_id);
    }
}
