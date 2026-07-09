<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BlogPostAnnotation extends Model
{
    use HasFactory, HasUuids;

    protected $attributes = [
        'status' => 'open',
    ];

    protected $fillable = [
        'blog_post_id',
        'organization_id',
        'user_id',
        'selected_text',
        'content',
        'status',
        'anchor_hash',
        'start_offset',
        'end_offset',
        'resolved_at',
        'resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
            'start_offset' => 'integer',
            'end_offset' => 'integer',
        ];
    }

    public function replies(): HasMany
    {
        return $this->hasMany(BlogAnnotationReply::class, 'annotation_id')->oldest();
    }

    public function blogPost(): BelongsTo
    {
        return $this->belongsTo(BlogPost::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
