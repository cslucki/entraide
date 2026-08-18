<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une entite reelle creee par un chargement de scenario pack (TASK-1240).
 *
 * Ecrite UNIQUEMENT par `App\Support\ScenarioPacks\ScenarioPackEntityRegistrar`.
 * `organization_id` est duplique depuis le `ScenarioPackLoad` parent
 * (defense en profondeur : verification cross-tenant possible sans
 * jointure).
 */
class ScenarioPackEntity extends Model
{
    use HasUuids;

    protected $fillable = [
        'scenario_pack_load_id',
        'organization_id',
        'entity_type',
        'internal_key',
        'entity_model',
        'entity_id',
        'sequence',
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
