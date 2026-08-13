<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberAiProfileInteraction extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'correlation_id',
        'process',
        'member_ai_profile_id',
        'profile_owner_user_id',
        'visitor_user_id',
        'visitor_type',
        'provider',
        'model',
        'status',
        'question',
        'response',
        'matched_fields',
        'metadata',
        'latency_ms',
        'input_tokens',
        'output_tokens',
        'cost_usd',
        'cost_unknown',
    ];

    protected function casts(): array
    {
        return [
            'matched_fields' => 'array',
            'metadata' => 'array',
            'latency_ms' => 'integer',
            // TASK-1132 : nullable des l'origine ici, pour que « aucun usage
            // rapporte » reste distinct de « 0 token ».
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_usd' => 'decimal:8',
            'cost_unknown' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MemberAiProfile::class, 'member_ai_profile_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'profile_owner_user_id');
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'visitor_user_id');
    }

    public function scopeForOrganization($query, $organization): void
    {
        $query->where('organization_id', $organization instanceof Model ? $organization->id : $organization);
    }
}
