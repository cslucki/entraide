<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un plugin est-il actif dans cette Boucle ? (TASK-1616)
 *
 * L'absence de ligne vaut « non actif ». Eteindre conserve la ligne —
 * `enabled = false` garde qui a coupe et quand — et n'efface AUCUNE posture
 * dans `loop_ai_assistants`.
 *
 * Pas de trait `HasOrganizationId`, pour la meme raison qu'a TASK-1614 : il
 * remplirait `organization_id` depuis `current_organization`, alors que la
 * seule valeur juste est celle de la BOUCLE. Un SuperAdmin agit depuis
 * `/admin`, hors de l'Organization concernee ; heriter de sa session
 * ecrirait la ligne dans le mauvais tenant.
 *
 * Lu et ecrit exclusivement par LoopPluginActivation.
 */
class LoopPlugin extends Model
{
    use HasUuids;

    protected $fillable = ['loop_id', 'organization_id', 'plugin_key', 'enabled', 'updated_by'];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function loop(): BelongsTo
    {
        return $this->belongsTo(Loop::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
