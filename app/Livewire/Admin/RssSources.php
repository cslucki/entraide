<?php

namespace App\Livewire\Admin;

use App\Models\RssSource;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * TASK-1790 — etape 1 du parcours individuel : les sources RSS connues.
 * TASK-1792 — bascule d'affichage des sources archivees (decision MASTER :
 * masquees par defaut, jamais supprimees).
 *
 * Route dans le groupe `['auth', 'admin']` (`routes/web.php`), donc deja
 * protegee par `AdminMiddleware` avant meme le montage du composant ; et
 * depuis TASK-1791, `AdminMiddleware` est aussi middleware persistant
 * Livewire — rien a dupliquer ici pour les mises a jour.
 *
 * Toujours volontairement minimal sur un point : pas de creation manuelle ni
 * de suppression physique — la seule facon de faire naitre une source reste
 * `AdminRssImportController::charger()` (ecran en lot).
 */
class RssSources extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'tailwind';

    public bool $afficherArchivees = false;

    /**
     * Pas de colonne de contenu chargee ici : seul le compte d'articles est
     * necessaire pour cette liste (CDC §10 — jamais tout charger d'avance).
     */
    public function render(): View
    {
        $sources = RssSource::query()
            ->when(! $this->afficherArchivees, fn ($q) => $q->nonArchivees())
            ->withCount('articles')
            ->orderByDesc('last_fetched_at')
            ->paginate(20);

        return view('livewire.admin.rss-sources', ['sources' => $sources]);
    }
}
