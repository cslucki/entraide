<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIInteractionLog extends Model
{
    use HasUuids;

    protected $table = 'ai_interaction_logs';

    protected $fillable = [
        'user_id',
        'community_id',
        'provider',
        'user_input',
        'detected_intent',
        'detected_category',
        'confidence_score',
        'raw_response',
        'is_debug',
    ];

    protected $casts = [
        'confidence_score' => 'float',
        'raw_response' => 'array',
        'is_debug' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }
}
