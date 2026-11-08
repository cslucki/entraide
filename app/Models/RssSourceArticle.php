<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-1789 — un article mis en cache depuis une `RssSource`.
 *
 * `source_identity` est la clé de correspondance avec `blog_posts.source_guid` —
 * même fabrique, voir `RssFeedReader::identite()`. Cette table ne porte aucune
 * notion de statut d'import : l'état "déjà importé / brouillon / publié / supprimé"
 * se lit en croisant cette valeur avec `blog_posts`, jamais stocké ici en double.
 */
class RssSourceArticle extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'rss_source_id',
        'source_identity',
        'guid',
        'url',
        'titre',
        'auteur',
        'resume',
        'contenu_html',
        'source_published_at',
        'fetched_at',
    ];

    protected $casts = [
        'source_published_at' => 'datetime',
        'fetched_at' => 'datetime',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(RssSource::class, 'rss_source_id');
    }
}
