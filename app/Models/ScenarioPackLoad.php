<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Etat courant d'un scenario pack charge dans une Organization (TASK-1240).
 *
 * Une ligne = (organization_id, pack_id). Ecrite UNIQUEMENT par
 * `App\Support\ScenarioPacks\ScenarioPackLoader` (creation/reload) et
 * `ScenarioPackResetter` (reset_at) ; supprimee par `ScenarioPackRemover`,
 * ce qui entraine la suppression en cascade des `ScenarioPackEntity`
 * associees.
 */
class ScenarioPackLoad extends Model
{
    use HasUuids;

    protected $fillable = [
        'pack_id',
        'pack_version',
        'organization_id',
        'loaded_at',
        'reset_at',
        // TASK-1351 : ce chargement a-t-il cree lui-meme son Organization ?
        // Seule provenance qui autorise le retrait a revenir a l'etat ABSENT.
        'organization_created_by_pack',
    ];

    protected function casts(): array
    {
        return [
            'loaded_at' => 'datetime',
            'reset_at' => 'datetime',
            'organization_created_by_pack' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function entities(): HasMany
    {
        return $this->hasMany(ScenarioPackEntity::class);
    }
}
