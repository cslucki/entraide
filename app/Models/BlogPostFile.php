<?php

namespace App\Models;

use App\Models\Traits\HasOrganizationId;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-1907 — un fichier PRIVE d'article (PDF ou audio).
 *
 * Jamais d'URL publique : le HTML de l'article ne porte que l'identifiant,
 * et la lecture passe par `BlogPostFileController`, qui applique les droits
 * de l'article (`BlogPostReadAccess`).
 */
class BlogPostFile extends Model
{
    use HasOrganizationId, HasUuids;

    public const KIND_PDF = 'pdf';

    public const KIND_AUDIO = 'audio';

    public const DISK = 'blog_files';

    protected $fillable = [
        'organization_id',
        'blog_post_id',
        'user_id',
        'kind',
        'disk',
        'path',
        'original_name',
        'mime',
        'size',
    ];

    protected $hidden = [
        'path',
        'disk',
        'extracted_text',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'extracted_at' => 'datetime',
        ];
    }

    public function blogPost(): BelongsTo
    {
        return $this->belongsTo(BlogPost::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
