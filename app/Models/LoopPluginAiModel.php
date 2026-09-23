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

    /**
     * TASK-1622 — le TYPE d'une ligne.
     *
     * `free_verified` : preuve de gratuite (`verified_free_at`), contrat
     * TASK-1617 inchange. `paid_approved` : modele payant explicitement
     * approuve (`approved_at` / `approved_by`), tarife au catalogue statique.
     */
    public const TYPE_FREE_VERIFIED = 'free_verified';

    public const TYPE_PAID_APPROVED = 'paid_approved';

    protected $fillable = [
        'plugin_key', 'assistant_key', 'provider', 'model_slug',
        'model_type', 'verified_free_at', 'approved_at', 'approved_by', 'updated_by',
    ];

    protected $casts = [
        'verified_free_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function isPaidApproved(): bool
    {
        return $this->model_type === self::TYPE_PAID_APPROVED;
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
