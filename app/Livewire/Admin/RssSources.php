<?php

namespace App\Livewire\Admin;

use App\Models\RssSource;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * TASK-1790 — etape 1 du parcours individuel : les sources RSS connues.
 *
 * Route dans le groupe `['auth', 'admin']` (`routes/web.php`), donc deja
 * protegee par `AdminMiddleware` avant meme le montage du composant — rien
 * a dupliquer ici.
 *
 * Volontairement minimal : ni creation, ni suppression, ni filtre, ni
 * recherche. La gestion des sources (cockpit) est le perimetre de la TASK
 * SUIVANTE, explicitement non demarree ici (decision MASTER).
 */
class RssSources extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'tailwind';

    /**
     * Pas de colonne de contenu chargee ici : seul le compte d'articles est
     * necessaire pour cette liste (CDC §10 — jamais tout charger d'avance).
     */
    public function render(): View
    {
        $sources = RssSource::query()
            ->withCount('articles')
            ->orderByDesc('last_fetched_at')
            ->paginate(20);

        return view('livewire.admin.rss-sources', ['sources' => $sources]);
    }
}
