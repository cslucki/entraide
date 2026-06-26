<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProfileAgentConversation extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'organization_id',
        'member_ai_profile_id',
        'profile_owner_user_id',
        'visitor_user_id',
        'visitor_session_id',
        'title',
    ];

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

    public function messages(): HasMany
    {
        return $this->hasMany(ProfileAgentMessage::class, 'conversation_id');
    }
}
