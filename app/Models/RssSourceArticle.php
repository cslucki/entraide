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

    /**
     * Reconstruit l'entree telle que `RssFeedReader::lire()` l'aurait rendue
     * — meme forme exactement, pour que `RssArticleImporter::importer()` ne
     * voie aucune difference entre un article fraichement lu et un article
     * relu depuis ce cache (TASK-1790). Fabrique UNIQUE, partagee par
     * l'ecran en lot (`AdminRssImportController::importer()`) et par la
     * fiche individuelle (`RssArticleFiche`) : deux reconstructions auraient
     * fini par diverger au premier champ ajoute a l'un des deux cotes.
     *
     * @return array{identite: string, guid: string|null, url: string|null, titre: string, auteur: string|null, resume: string|null, contenu_html: string|null, publie_le: string|null}
     */
    public function versEntreeImport(): array
    {
        return [
            'identite' => $this->source_identity,
            'guid' => $this->guid,
            'url' => $this->url,
            'titre' => $this->titre,
            'auteur' => $this->auteur,
            'resume' => $this->resume,
            'contenu_html' => $this->contenu_html,
            'publie_le' => optional($this->source_published_at)->toDateTimeString(),
        ];
    }
}
