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
    ];

    protected function casts(): array
    {
        return [
            'matched_fields' => 'array',
            'metadata' => 'array',
            'latency_ms' => 'integer',
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
