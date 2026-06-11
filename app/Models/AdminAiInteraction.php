<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminAiInteraction extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'admin_ai_interactions';

    protected $fillable = [
        'organization_id',
        'user_id',
        'scenario_id',
        'provider',
        'model',
        'status',
        'input_excerpt',
        'input_hash',
        'input_length',
        'result_summary',
        'result_payload',
        'metadata',
        'input_tokens',
        'output_tokens',
        'latency_ms',
        'cost_usd',
        'review_status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'result_payload' => 'array',
            'metadata' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'input_length' => 'integer',
            'latency_ms' => 'integer',
            'cost_usd' => 'decimal:8',
            'reviewed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeNeedsReview($query): void
    {
        $query->whereNull('review_status')
            ->where(function ($q) {
                $q->whereRaw("CAST(result_payload->>'moderation_flag' AS boolean) = true")
                  ->orWhereRaw("result_payload->>'risk_level' = 'high'")
                  ->orWhereRaw("CAST(result_payload->>'needs_human_category_review' AS boolean) = true");
            });
    }
}
