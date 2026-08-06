<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleSeriesItem extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'article_series_id',
        'blog_post_id',
        'dossier_file_id',
        'position',
        'added_by',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(ArticleSeries::class, 'article_series_id');
    }

    public function dossierFile(): BelongsTo
    {
        return $this->belongsTo(DossierFile::class);
    }

    /**
     * Le contenu de cet item, quel qu'il soit.
     *
     * Un item porte **exactement un** Article ou un fichier — jamais les deux,
     * jamais aucun. La regle est tenue par DossierSeriesService ; ce raccourci
     * evite qu'un appelant ait a redemander lequel des deux est renseigne.
     */
    public function content(): ?Model
    {
        return $this->blogPost ?? $this->dossierFile;
    }

    /** `article` ou `file`. */
    public function contentType(): ?string
    {
        if ($this->blog_post_id !== null) {
            return 'article';
        }

        return $this->dossier_file_id !== null ? 'file' : null;
    }

    /** Ce qu'on affiche pour cet item, sans savoir de quoi il s'agit. */
    public function displayName(): string
    {
        if ($this->blog_post_id !== null) {
            return (string) ($this->blogPost?->title ?? '');
        }

        return (string) ($this->dossierFile?->display_name ?: $this->dossierFile?->original_name ?? '');
    }

    public function blogPost(): BelongsTo
    {
        return $this->belongsTo(BlogPost::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
