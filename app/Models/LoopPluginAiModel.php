<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Le modele OpenRouter affecte a un assistant, decide par le SuperAdmin.
 * (TASK-1617)
 *
 * PLATEFORME : aucune colonne de tenant. Les postures Loop-scoped vivent
 * ailleurs (`loop_ai_assistants`).
 *
 * Lu et ecrit exclusivement par LoopPluginAiModels.
 */
class LoopPluginAiModel extends Model
{
    use HasUuids;

    protected $fillable = ['plugin_key', 'assistant_key', 'provider', 'model_slug', 'verified_free_at', 'updated_by'];

    protected $casts = [
        'verified_free_at' => 'datetime',
    ];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
