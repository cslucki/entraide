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

    /**
     * TASK-1642 — identite d'idempotence d'un chargement de manifeste.
     *
     * DELIBEREMENT absente de `$fillable` : c'est le digest APPROUVE par un
     * humain, ecrit par le seul service qui charge un manifeste. Un mass
     * assignment qui pourrait le poser permettrait de faire passer un
     * chargement pour un autre.
     */
    public const MANIFEST_DIGEST = 'manifest_digest';

    protected function casts(): array
    {
        return [
            'loaded_at' => 'datetime',
            'reset_at' => 'datetime',
            'organization_created_by_pack' => 'boolean',
        ];
    }

    /**
     * La sandbox de ce chargement, MEME en corbeille.
     *
     * TASK-1650, trouve en revue : `Organization` est en SoftDeletes. Sans
     * `withTrashed()`, une sandbox mise a la corbeille rendait cette relation
     * nulle, l'ecran de la version perdait son panneau — donc Reset et Remove
     * — et proposait « Approuver » a la place. La sandbox et ses comptes
     * redevenaient irretirables PAR L'ECRAN, ce que cette TASK repare
     * justement cote service.
     *
     * Un chargement designe sa sandbox : que quelqu'un l'ait mise a la
     * corbeille ne la fait pas cesser d'exister, et c'est precisement l'etat
     * ou il faut pouvoir agir.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class)->withTrashed();
    }

    public function entities(): HasMany
    {
        return $this->hasMany(ScenarioPackEntity::class);
    }
}
