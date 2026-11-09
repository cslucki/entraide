<?php

namespace App\Models;

use App\Services\Blog\RssArticleImporter;
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
        'disabled_at',
        'archived_at',
    ];

    protected $casts = [
        'last_fetched_at' => 'datetime',
        'disabled_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function articles(): HasMany
    {
        return $this->hasMany(RssSourceArticle::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * TASK-1792 — deux etats INDEPENDANTS, voir la migration
     * `add_disabled_and_archived_to_rss_sources_table` pour la raison des
     * dates plutot que des booleens.
     */
    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function scopeNonArchivees($query)
    {
        return $query->whereNull('archived_at');
    }

    /**
     * Enregistre des articles DEJA lus (fetch + parsing faits par l'appelant,
     * qui reste seul responsable de la gestion d'erreur reseau) : UPSERT par
     * identite, jamais de suppression d'un article absent de cette lecture —
     * voir le docbloc de `RssSourceArticle`. Fabrique UNIQUE, partagee par
     * `AdminRssImportController::charger()` (ecran en lot, TASK-1785) et par
     * `RssSourceArticles::actualiser()` (TASK-1792) : deux boucles d'upsert
     * auraient fini par diverger au premier champ ajoute a l'un des deux.
     *
     * @param  list<array{identite: string, guid: string|null, url: string|null, titre: string, auteur: string|null, resume: string|null, contenu_html: string|null, publie_le: string|null}>  $articles
     */
    public function enregistrerArticlesDecouverts(array $articles): void
    {
        $maintenant = now();

        foreach ($articles as $entree) {
            $this->articles()->updateOrCreate(
                ['source_identity' => $entree['identite']],
                [
                    'guid' => $entree['guid'],
                    'url' => $entree['url'],
                    'titre' => $entree['titre'],
                    'auteur' => $entree['auteur'],
                    'resume' => $entree['resume'],
                    'contenu_html' => $entree['contenu_html'],
                    'source_published_at' => RssArticleImporter::dateSource($entree['publie_le']),
                    'fetched_at' => $maintenant,
                ],
            );
        }

        $this->update(['last_fetched_at' => $maintenant]);
    }
}
