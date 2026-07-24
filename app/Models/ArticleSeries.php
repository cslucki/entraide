<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArticleSeries extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'dossier_id',
        'root_blog_post_id',
        'created_by',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class);
    }

    public function rootBlogPost(): BelongsTo
    {
        return $this->belongsTo(BlogPost::class, 'root_blog_post_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ArticleSeriesItem::class)->orderBy('position')->orderBy('created_at');
    }

    public function annexes(): BelongsToMany
    {
        return $this->belongsToMany(BlogPost::class, 'article_series_items')
            ->withPivot('id', 'organization_id', 'added_by', 'position')
            ->withTimestamps()
            ->orderByPivot('position')
            ->orderBy('blog_posts.created_at');
    }
}
