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
}
