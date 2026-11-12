<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-1808 §2.D — le journal d'audit du cockpit d'evaluations.
 *
 * Une ligne par INTERVENTION, jamais par avis : modifier puis masquer la
 * meme evaluation ecrit DEUX lignes, chacune portant son propre « avant »,
 * son acteur, sa date et son motif — exactement ce que le CDC demande.
 * Jamais modifiee une fois ecrite (`const UPDATED_AT = null`, meme patron
 * que `LoginLog`), jamais relue pour decider quoi que ce soit en PROD — le
 * comportement courant vit sur `Review` (`hidden_at`, `deleted_at`), ce
 * journal n'est qu'une trace.
 */
class ReviewModerationLog extends Model
{
    use HasFactory, HasUuids;

    const UPDATED_AT = null;

    public const ACTION_UPDATED = 'updated';

    public const ACTION_HIDDEN = 'hidden';

    public const ACTION_UNHIDDEN = 'unhidden';

    public const ACTION_DELETED = 'deleted';

    public const ACTION_RESTORED = 'restored';

    /** @var list<string> */
    public const ACTIONS = [
        self::ACTION_UPDATED,
        self::ACTION_HIDDEN,
        self::ACTION_UNHIDDEN,
        self::ACTION_DELETED,
        self::ACTION_RESTORED,
    ];

    protected $fillable = [
        'review_id',
        'organization_id',
        'actor_id',
        'action',
        'original_rating',
        'original_comment',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'original_rating' => 'integer',
        ];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
