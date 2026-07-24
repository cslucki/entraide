<?php

namespace App\Models;

use App\Models\Traits\HasOrganizationId;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DossierChunk extends Model
{
    use HasFactory;
    use HasOrganizationId;
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'dossier_id',
        'blog_post_id',
        'chunk_index',
        'content',
        'content_hash',
        'token_count',
        'embedding',
        'embedding_provider',
        'embedding_model',
        'indexed_at',
    ];

    protected function casts(): array
    {
        return [
            'chunk_index' => 'integer',
            'token_count' => 'integer',
            'embedding' => 'array',
            'indexed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class);
    }

    public function blogPost(): BelongsTo
    {
        return $this->belongsTo(BlogPost::class);
    }
}
