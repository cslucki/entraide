<?php

namespace App\Models;

use App\Models\Traits\HasOrganizationId;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class FeedPost extends Model
{
    use HasFactory, HasOrganizationId, HasUuids, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'user_id',
        'type',
        'title',
        'content',
        'image_path',
        'url_preview',
        'status',
        'pinned_at',
        'pinned_by_id',
    ];

    protected $casts = [
        'pinned_at' => 'datetime',
        'url_preview' => 'array',
    ];

    public const TYPE_ANNOUNCEMENT = 'announcement';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_DRAFT = 'draft';

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pinnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pinned_by_id');
    }

    public function reactions(): MorphMany
    {
        return $this->morphMany(Reaction::class, 'reactionable');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(FeedPostComment::class)
            ->where('is_approved', true)
            ->latest();
    }

    public function loops(): BelongsToMany
    {
        return $this->belongsToMany(Loop::class, 'feed_post_loop')
            ->withTimestamps();
    }

    public function scopePublished($query)
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function scopePinned($query)
    {
        return $query->whereNotNull('pinned_at');
    }

    public function scopeForOrganization($query, string $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function isPinned(): bool
    {
        return $this->pinned_at !== null;
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }
}
