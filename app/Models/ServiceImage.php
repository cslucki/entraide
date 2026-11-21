<?php

namespace App\Models;

use App\Models\Traits\HasOrganizationId;
use App\Support\Images\ImageVariantResolver;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceImage extends Model
{
    use HasFactory, HasOrganizationId, HasUuids;

    protected $fillable = ['service_id', 'path', 'order', 'organization_id'];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** TASK-1873 — pleine taille (1200 px, WebP) quand elle est prete : la lightbox. */
    public function getUrlAttribute(): string
    {
        return (string) app(ImageVariantResolver::class)->url($this->path, 'service_full');
    }

    /**
     * TASK-1873 — vignette 400 px. Pointait sur `thumbnails/…`, produit par
     * `GenerateServiceThumbnail` sur une queue jamais consommee en PROD : un
     * lien vraisemblablement mort. Repli desormais sur l'original.
     */
    public function getThumbnailUrlAttribute(): string
    {
        return (string) app(ImageVariantResolver::class)->url($this->path, 'service_thumb');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
