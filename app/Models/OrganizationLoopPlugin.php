<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une decision de disponibilite : ce plugin, dans cette Organization.
 *
 * TASK-1614. L'absence de ligne vaut « non disponible » — c'est le defaut, et
 * il est ferme. Une ligne existe des qu'un SuperAdmin s'est prononce, et elle
 * SURVIT a l'extinction : `available = false` garde `updated_by` et
 * `updated_at`, donc qui a coupe et quand.
 *
 * Lu et ecrit exclusivement par LoopPluginAvailabilityService.
 *
 * **Pas de trait HasOrganizationId, deliberement.** Ce trait remplit
 * `organization_id` depuis `current_organization` quand il est absent. C'est
 * juste pour une ecriture faite DANS un tenant ; c'est faux ici. Le SuperAdmin
 * ecrit pour l'Organization qu'il DESIGNE, et elle n'a aucune raison d'etre
 * celle de sa propre session. Herite en silence, ce serait la faute
 * inter-tenant exacte que cette TASK doit rendre impossible : on exige donc un
 * `organization_id` explicite a chaque ecriture.
 */
class OrganizationLoopPlugin extends Model
{
    use HasUuids;

    protected $fillable = ['organization_id', 'plugin_key', 'available', 'updated_by'];

    protected $casts = [
        'available' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
