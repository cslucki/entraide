<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-1256 : le jugement d'un humain sur UNE reponse IA (`ai_interactions`).
 *
 * Table fille ancree par FK CASCADE : memes droits, meme tenant, meme
 * retention que l'interaction jugee. `comment` et `suggested_response` sont
 * le contenu de l'humain (pourquoi / quoi ameliorer / quelle meilleure
 * reponse), jamais une copie du prompt ou de la reponse IA.
 *
 * Un verdict n'est PAS un consentement d'entrainement : aucun champ
 * export / training / consent n'existe ici, par construction.
 */
class AiInteractionFeedback extends Model
{
    use HasUuids;

    /** Eloquent tient « feedback » pour indenombrable : le nom est explicite. */
    protected $table = 'ai_interaction_feedbacks';

    public const VERDICT_HELPFUL = 'helpful';

    public const VERDICT_IMPROVE = 'improve';

    /** @var list<string> les deux seules valeurs admises */
    public const VERDICTS = [self::VERDICT_HELPFUL, self::VERDICT_IMPROVE];

    protected $fillable = [
        'ai_interaction_id',
        'organization_id',
        'user_id',
        'verdict',
        'comment',
        'suggested_response',
    ];

    public function interaction(): BelongsTo
    {
        return $this->belongsTo(AiInteraction::class, 'ai_interaction_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
