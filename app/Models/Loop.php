<?php

namespace App\Models;

use App\Models\Traits\HasOrganizationId;
use Database\Factories\LoopFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Loop extends Model
{
    /** @use HasFactory<LoopFactory> */
    use HasFactory, HasOrganizationId, HasUuids;

    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'description',
        'type',
        'status',
        'visibility',
        'created_by',
        'member_ai_profile_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => 'string',
            'status' => 'string',
            'visibility' => 'string',
        ];
    }

    public function scopePublic($query)
    {
        return $query->where('visibility', 'public');
    }

    public function scopePrivate($query)
    {
        return $query->where('visibility', 'private');
    }

    public function isPublic(): bool
    {
        return $this->visibility === 'public';
    }

    public function isPrivate(): bool
    {
        return $this->visibility === 'private';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members(): HasMany
    {
        return $this->hasMany(LoopMember::class);
    }

    public function activeMembers(): HasMany
    {
        return $this->hasMany(LoopMember::class)->where('status', 'active');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(LoopMessage::class);
    }

    public function memberAiProfile(): BelongsTo
    {
        return $this->belongsTo(MemberAiProfile::class);
    }

    public function isAiAgent(): bool
    {
        return $this->type === 'ai_agent';
    }
}
