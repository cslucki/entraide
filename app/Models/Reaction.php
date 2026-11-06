<?php

namespace App\Models;

use App\Models\Traits\HasOrganizationId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Reaction extends Model
{
    use HasFactory, HasOrganizationId;

    protected $fillable = [
        'organization_id',
        'user_id',
        'reactionable_id',
        'reactionable_type',
        'reaction_type',
    ];

    /**
     * TASK-1772 (recette Cyril, 06/11) — « on va supprimer la reaction seen
     * (les deux yeux), ainsi sur smartphone on aura plus de place ».
     *
     * Retiree des DEUX listes : celle-ci ordonne le choix propose, et
     * `emojiMap()` sert de catalogue a toutes les surfaces (le fil, la
     * messagerie, les cartes de flux, qui en prennent les six premieres).
     * La laisser dans l'une et pas dans l'autre rendrait une pastille vide
     * ici, ou un choix fantome la-bas.
     *
     * Mesure avant retrait, sur la base REELLEMENT servie
     * (`bouclepro_prod_work`, et non `bouclepro_ai_validation` que lit
     * `APP_ENV=ai-validation`) : 4 reactions en base — thumbs_up 2, heart 1,
     * thanks 1 — et AUCUNE du type retire. Une reaction de ce type creee en
     * PROD cesserait d'etre AFFICHEE ; la ligne, elle, reste en base.
     */
    public const REACTION_TYPES = [
        'thumbs_up',
        'heart',
        'thanks',
        'surprised',
        'interesting',
        'smile',
        'sad',
        'angry',
        'fear',
    ];

    public const ALLOWED_REACTIONABLE_TYPES = [
        LoopMessage::class,
        Message::class,
        FeedPost::class,
    ];

    public static function emojiMap(): array
    {
        return [
            'thumbs_up' => '👍',
            'heart' => '❤️',
            'thanks' => '🙏',
            'surprised' => '😮',
            'interesting' => '💡',
            'smile' => '😊',
            'sad' => '😢',
            'angry' => '😠',
            'fear' => '😨',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reactionable(): MorphTo
    {
        return $this->morphTo();
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
