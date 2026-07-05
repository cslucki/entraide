<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlogSnapshot extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'blog_post_id',
        'name',
        'comment',
        'title',
        'summary',
        'content',
        'meta_title',
        'meta_description',
        'status',
        'metadata',
        'created_by',
        'updated_by',
        'restored_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'restored_at' => 'datetime',
        ];
    }

    public function blogPost(): BelongsTo
    {
        return $this->belongsTo(BlogPost::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
