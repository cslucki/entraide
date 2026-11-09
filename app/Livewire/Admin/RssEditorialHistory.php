<?php

namespace App\Livewire\Admin;

use App\Models\Organization;
use App\Models\RssSource;
use App\Models\RssSourceArticle;
use App\Services\Blog\RssArticleImporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * TASK-1792 — l'historique editorial : TOUS les articles RSS decouverts,
 * toutes sources, filtrables par Source / Organization / Statut editorial /
 * Date, plus une recherche texte sur le titre.
 *
 * ## Le statut n'est PAS stocke ici (decision MASTER explicite)
 *
 * « Utiliser `source_guid` et les donnees existantes. Ne pas dupliquer
 * inutilement les informations. » — le statut d'un article se LIT depuis
 * `blog_posts` au moment du rendu (`RssArticleImporter::statutsParOrganizationPourPlusieurs()`,
 * meme fabrique que la fiche individuelle, TASK-1790), jamais recopie dans
 * une colonne de ce composant ou de `rss_source_articles`.
 *
 * ## Semantique du filtre Statut SANS Organization choisie
 *
 * Un article a un statut DIFFERENT par Organization. Sans filtre
 * d'Organization : « non importe » signifie aucun `BlogPost` dans AUCUNE
 * Organization ; brouillon/publie/supprime signifient AU MOINS une
 * Organization dans cet etat. Avec un filtre d'Organization choisi, le
 * statut devient celui de CETTE Organization precisement. Interpretation
 * posee au precheck, a confirmer au checkpoint.
 *
 * ## Pas de N+1 (CDC §10)
 *
 * Le statut de chaque ligne de la page affichee est lu en UNE requete
 * groupee (`statutsParOrganizationPourPlusieurs()`), jamais une requete par
 * article. Le filtre Statut/Organization, lui, est applique en SQL via une
 * sous-requete corrélée sur `blog_posts` — la base filtre, jamais PHP apres
 * coup sur une page entiere chargee pour rien.
 */
class RssEditorialHistory extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'tailwind';

    public string $sourceId = '';

    public string $organizationId = '';

    public string $statut = '';

    public string $dateDepuis = '';

    public string $dateJusqua = '';

    public string $recherche = '';

    public function updatedSourceId(): void
    {
        $this->resetPage();
    }

    public function updatedOrganizationId(): void
    {
        $this->resetPage();
    }

    public function updatedStatut(): void
    {
        $this->resetPage();
    }

    public function updatedDateDepuis(): void
    {
        $this->resetPage();
    }

    public function updatedDateJusqua(): void
    {
        $this->resetPage();
    }

    public function updatedRecherche(): void
    {
        $this->resetPage();
    }

    public function render(RssArticleImporter $importer): View
    {
        $query = RssSourceArticle::query()
            ->with(['source:id,label,url'])
            ->select(['id', 'rss_source_id', 'titre', 'source_identity', 'source_published_at']);

        if ($this->sourceId !== '') {
            $query->where('rss_source_id', $this->sourceId);
        }

        if (trim($this->recherche) !== '') {
            $query->where('titre', 'like', '%'.trim($this->recherche).'%');
        }

        if ($this->dateDepuis !== '') {
            $query->whereDate('source_published_at', '>=', $this->dateDepuis);
        }

        if ($this->dateJusqua !== '') {
            $query->whereDate('source_published_at', '<=', $this->dateJusqua);
        }

        $this->appliquerFiltreStatut($query);

        $articles = $query->orderByDesc('source_published_at')->paginate(20);

        $statuts = $importer->statutsParOrganizationPourPlusieurs(
            $articles->pluck('source_identity')->all(),
        );

        // Noms d'Organization pour l'affichage des badges — une seule
        // requete, independante de la pagination.
        $organisationsParId = Organization::query()
            ->get(['id', 'name'])
            ->keyBy(fn (Organization $o) => (string) $o->id);

        return view('livewire.admin.rss-editorial-history', [
            'articles' => $articles,
            'statuts' => $statuts,
            'organisationsParId' => $organisationsParId,
            'sources' => RssSource::query()->orderByRaw('COALESCE(label, url)')->get(['id', 'label', 'url']),
            'organizations' => Organization::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Sous-requete CORRELEE sur `blog_posts`, jamais une lecture PHP apres
     * coup — voir le docbloc de classe. `source_guid` est la colonne qui
     * porte EXACTEMENT la meme valeur que `source_identity` (meme fabrique,
     * `RssFeedReader::identite()`).
     */
    private function appliquerFiltreStatut(Builder $query): void
    {
        if ($this->statut === '' && $this->organizationId === '') {
            return;
        }

        $correlation = function ($sub) {
            $sub->from('blog_posts')->whereColumn('blog_posts.source_guid', 'rss_source_articles.source_identity');

            if ($this->organizationId !== '') {
                $sub->where('blog_posts.organization_id', $this->organizationId);
            }

            return $sub;
        };

        match ($this->statut) {
            'non_importe' => $query->whereNotExists($correlation),
            'brouillon' => $query->whereExists(fn ($sub) => $correlation($sub)
                ->whereNull('blog_posts.deleted_at')
                ->where('blog_posts.status', '!=', 'published')),
            'publie' => $query->whereExists(fn ($sub) => $correlation($sub)
                ->whereNull('blog_posts.deleted_at')
                ->where('blog_posts.status', 'published')),
            'supprime' => $query->whereExists(fn ($sub) => $correlation($sub)
                ->whereNotNull('blog_posts.deleted_at')),
            // Pas de statut precis : seule l'Organization est filtree, dans
            // n'importe quel etat (y compris supprime — l'Organization EST
            // concernee par cette identite, c'est la question posee).
            default => $query->whereExists($correlation),
        };
    }
}
