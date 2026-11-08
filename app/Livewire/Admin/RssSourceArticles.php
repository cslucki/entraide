<?php

namespace App\Livewire\Admin;

use App\Models\RssSource;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * TASK-1790 — etape 2 : les articles d'UNE source.
 *
 * `$rssSource` est resolu par le binding de route (`{rssSource}` dans
 * `routes/web.php`) et VERROUILLE — meme garde que `annonceId` dans
 * `EntraideComments` : rien dans l'ecran ne doit pouvoir faire deriver quelle
 * source est affichee, y compris par un appel direct a l'API Livewire qui
 * contournerait le HTML rendu.
 */
class RssSourceArticles extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'tailwind';

    #[Locked]
    public RssSource $rssSource;

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
