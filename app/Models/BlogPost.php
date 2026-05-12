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
use Illuminate\Support\Str;

class BlogPost extends Model
{
    use HasFactory, HasOrganizationId, HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id',
        'community_id',
        'organization_id',
        'title',
        'slug',
        'summary',
        'content',
        'image',
        'status',
        'published_at',
        'views_count',
        'read_time',
        'meta_title',
        'meta_description',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'views_count' => 'integer',
        'read_time' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $post) {
            if (empty($post->slug) && $post->title) {
                $post->slug = Str::slug($post->title);
            }
            // Estime le temps de lecture (200 mots/min)
            $wordCount = str_word_count(strip_tags($post->content ?? ''));
            $post->read_time = max(1, (int) ceil($wordCount / 200));
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'blog_post_category');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'blog_post_tag');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(BlogComment::class)->whereNull('parent_id')->where('is_approved', true)->latest();
    }

    public function likes(): MorphMany
    {
        return $this->morphMany(Like::class, 'likeable');
    }

    public function isLikedBy(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->likes()->where('user_id', $user->id)->exists();
    }

    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image) {
            return null;
        }

        return Storage::disk('public')->url($this->image);
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
