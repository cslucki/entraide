<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * TASK-1789 — un flux RSS connu.
 *
 * Délibérément sans `organization_id` : une source est un catalogue d'outillage
 * SuperAdmin, pas une fonctionnalité métier Organization-scoped. Voir la migration
 * `create_rss_sources_table` pour la décision MASTER qui le justifie.
 */
class RssSource extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'url',
        'label',
        'created_by',
        'last_fetched_at',
    ];

    protected $casts = [
        'last_fetched_at' => 'datetime',
    ];

    public function articles(): HasMany
    {
        return $this->hasMany(RssSourceArticle::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
