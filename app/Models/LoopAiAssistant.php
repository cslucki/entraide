<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * L'ecart d'une Boucle par rapport a la posture par defaut d'un assistant.
 * (TASK-1616)
 *
 * Une ligne existe SEULEMENT la ou quelqu'un s'est ecarte du catalogue.
 * `instruction = null` signifie « la posture du catalogue », jamais « pas
 * d'instruction ». Vider le champ supprime l'ecart plutot que de recopier le
 * defaut en base — sinon la Boucle cesserait de suivre l'evolution du produit
 * des le premier enregistrement.
 *
 * Ces lignes SURVIVENT a l'extinction du plugin, dans la Boucle comme dans
 * l'Organization : rallumer doit rendre ses postures, pas les redemander.
 *
 * Lu et ecrit exclusivement par LoopAiAssistants.
 */
class LoopAiAssistant extends Model
{
    use HasUuids;

    protected $fillable = ['loop_id', 'organization_id', 'key', 'instruction', 'enabled', 'order', 'updated_by'];

    protected $casts = [
        'enabled' => 'boolean',
        'order' => 'integer',
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
