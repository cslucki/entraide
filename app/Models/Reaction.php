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

    public const REACTION_TYPES = [
        'thumbs_up',
        'heart',
        'thanks',
        'surprised',
        'seen',
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
            'seen' => '👀',
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
