<?php

namespace App\Livewire\Admin;

use App\Models\RssSource;
use App\Services\Blog\RssFeedFetcher;
use App\Services\Blog\RssFeedReader;
use Illuminate\Support\Facades\Lang;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

/**
 * TASK-1790 — etape 2 : les articles d'UNE source.
 * TASK-1792 — gestion de la source : renommer, actualiser, desactiver,
 * archiver. Jamais de suppression physique (decision MASTER).
 *
 * `$rssSource` est resolu par le binding de route (`{rssSource}` dans
 * `routes/web.php`) et VERROUILLE — meme garde que `annonceId` dans
 * `EntraideComments` : rien dans l'ecran ne doit pouvoir faire deriver quelle
 * source est affichee, y compris par un appel direct a l'API Livewire qui
 * contournerait le HTML rendu.
 *
 * Pas de controle `is_admin` explicite ICI : `AdminMiddleware` est declare
 * middleware persistant Livewire depuis TASK-1791
 * (`AppServiceProvider::boot()`) — protection deja en place pour TOUT
 * composant `App\Livewire\Admin\*`, celui-ci inclus, sans redite necessaire.
 */
#[Layout('layouts.admin')]
class RssSourceArticles extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'tailwind';

    #[Locked]
    public RssSource $rssSource;

    public string $label = '';

    public ?string $message = null;

    public ?string $erreur = null;

    public function mount(): void
    {
        $this->label = (string) $this->rssSource->label;
    }

    public function renommer(): void
    {
        $this->message = null;
        $this->erreur = null;

        $valide = trim($this->label);

        $this->rssSource->update(['label' => $valide === '' ? null : $valide]);
        $this->label = (string) $this->rssSource->label;

        $this->message = __('admin.import_rss.sources.renommer_succes');
    }

    /**
     * Refuse cote serveur si la source est desactivee, MEME si le bouton
     * aurait du etre masque — jamais de confiance dans l'etat du navigateur
     * (meme doctrine que `RssArticleFiche::creerBrouillon()`, TASK-1790/1791).
     */
    public function actualiser(RssFeedFetcher $fetcher, RssFeedReader $reader): void
    {
        $this->message = null;
        $this->erreur = null;

        if ($this->rssSource->isDisabled()) {
            return;
        }

        try {
            $recupere = $fetcher->recuperer($this->rssSource->url);
            $articles = $reader->lire($recupere['xml']);
        } catch (RuntimeException $e) {
            // Meme traitement que AdminRssImportController::charger() : le
            // code est STABLE et traduit a l'ecran, jamais un message brut
            // de transport.
            $cle = 'admin.import_rss.erreurs.'.$e->getMessage();
            $this->erreur = Lang::has($cle) ? __($cle) : $e->getMessage();

            return;
        }

        $this->rssSource->enregistrerArticlesDecouverts($articles);
        $this->rssSource->refresh();

        $this->message = __('admin.import_rss.sources.actualiser_succes', ['nombre' => count($articles)]);
    }

    public function basculerActivation(): void
    {
        $this->rssSource->update([
            'disabled_at' => $this->rssSource->isDisabled() ? null : now(),
        ]);
    }

    public function basculerArchivage(): void
    {
        $this->rssSource->update([
            'archived_at' => $this->rssSource->isArchived() ? null : now(),
        ]);
    }

    /**
     * Liste SANS `contenu_html` ni `resume` (CDC §10) : cette colonne peut
     * peser plusieurs dizaines de Ko par ligne, et seule la fiche
     * individuelle en a besoin.
     */
    public function render(): View
    {
        $articles = $this->rssSource->articles()
            ->orderByDesc('source_published_at')
            ->select(['id', 'rss_source_id', 'titre', 'auteur', 'url', 'source_published_at'])
            ->paginate(20);

        return view('livewire.admin.rss-source-articles', ['articles' => $articles]);
    }
}
