<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlogAnnotationReply extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'annotation_id',
        'user_id',
        'content',
    ];

    public function annotation(): BelongsTo
    {
        return $this->belongsTo(BlogPostAnnotation::class, 'annotation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
