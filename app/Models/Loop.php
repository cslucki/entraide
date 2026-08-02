<?php

namespace App\Models;

use App\Models\Traits\HasOrganizationId;
use Database\Factories\LoopFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Loop extends Model
{
    /** @use HasFactory<LoopFactory> */
    use HasFactory, HasOrganizationId, HasUuids;

    public const ACCESS_OPEN = 'open';

    public const ACCESS_REQUEST = 'request';

    public const ACCESS_INVITATION = 'invitation';

    public const ACCESS_MODES = [self::ACCESS_OPEN, self::ACCESS_REQUEST, self::ACCESS_INVITATION];

    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'description',
        'tagline',
        'cover_image_path',
        'type',
        'status',
        'visibility',
        'access_mode',
        'created_by',
        'member_ai_profile_id',
        'manifesto_blog_post_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => 'string',
            'status' => 'string',
            'visibility' => 'string',
            'access_mode' => 'string',
        ];
    }

    public static function isValidAccessMode(string $mode): bool
    {
        return in_array($mode, self::ACCESS_MODES, true);
    }

    public function isOpenAccess(): bool
    {
        return $this->access_mode === self::ACCESS_OPEN;
    }

    public function isRequestAccess(): bool
    {
        return $this->access_mode === self::ACCESS_REQUEST;
    }

    public function isInvitationAccess(): bool
    {
        return $this->access_mode === self::ACCESS_INVITATION;
    }

    public function getCoverImageUrlAttribute(): ?string
    {
        if (! $this->cover_image_path) {
            return null;
        }

        return Storage::disk('public')->url($this->cover_image_path);
    }

    public function scopePublic($query)
    {
        return $query->where('visibility', 'public');
    }

    public function scopePrivate($query)
    {
        return $query->where('visibility', 'private');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeArchived($query)
    {
        return $query->where('status', 'archived');
    }

    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function isPublic(): bool
    {
        return $this->visibility === 'public';
    }

    public function isPrivate(): bool
    {
        return $this->visibility === 'private';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    public function hasContent(): bool
    {
        return $this->messages()->exists();
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

    public function roadmapItems(): HasMany
    {
        return $this->hasMany(LoopRoadmapItem::class);
    }

    public function joinRequests(): HasMany
    {
        return $this->hasMany(LoopJoinRequest::class);
    }

    public function pendingJoinRequests(): HasMany
    {
        return $this->hasMany(LoopJoinRequest::class)->where('status', LoopJoinRequest::STATUS_PENDING);
    }

    /**
     * The designated primary Manifesto (a BlogPost). Null if none is designated
     * or if the designated post has been (soft) deleted (SoftDeletes scope).
     */
    public function manifesto(): BelongsTo
    {
        return $this->belongsTo(BlogPost::class, 'manifesto_blog_post_id');
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
