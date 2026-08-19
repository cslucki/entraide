<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiInteraction extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'organization_id',
        'correlation_id',
        'process',
        'feature',
        'model',
        'prompt',
        'response',
        'input_tokens',
        'output_tokens',
        'cost_usd',
        'cost_unknown',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_usd' => 'decimal:6',
            // TASK-1132 : tri-etat. null = statut non evalue (lignes
            // historiques), false = cout connu (un 0 legitime inclus),
            // true = cout non mesurable, `cost_usd` valant alors NULL.
            'cost_unknown' => 'boolean',
        ];
    }

    public const UPDATED_AT = null;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * TASK-1256 : jugements humains portes sur cette reponse (un par
     * personne), supprimes avec elle (FK CASCADE).
     */
    public function feedbacks(): HasMany
    {
        return $this->hasMany(AiInteractionFeedback::class, 'ai_interaction_id');
    }
}
