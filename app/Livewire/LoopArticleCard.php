<?php

namespace App\Livewire;

use App\Models\BlogPost;
use App\Models\Loop;
use App\Services\Loops\LoopWritingService;
use App\Support\Loops\LoopPermissionResolver;
use Livewire\Component;

/**
 * La Card Article : l'atelier d'ecriture d'une Boucle de Redaction.
 *
 * **Aucun second systeme.** Les Articles sont des `BlogPost` ranges dans le
 * Dossier de la Boucle ; l'editeur, les audiences, les snapshots, les
 * co-auteurs et les Series existent depuis longtemps, avec leurs routes et
 * leurs policies. Cette Card **lit** ces objets sous un autre angle et renvoie
 * aux parcours existants pour ecrire — elle ne porte aucun formulaire.
 *
 * Les invariants de la serie sont tenus des la conception :
 *
 * 1. **Ecrire est une ecriture** : `writing.compose`, sans `read`. Une Boucle
 *    archivee la refuse.
 * 2. **Le cout ne croit pas** : chaque liste est bornee, les relations chargees
 *    en une fois.
 * 3. **Chaque geste a un chemin**, teste au refus, au succes **et** a l'ecran.
 * 4. **Aucun droit ne fuit** : les brouillons des autres sont annonces, jamais
 *    ouverts — on dit qu'un texte existe, pas ce qu'il raconte.
 *
 * Les droits sont lus une fois par rendu, dans un cache **prive** vide a
 * l'entree et a la sortie de `render()` : l'unite de vie d'une instance
 * Livewire est le commit, et un commit porte plusieurs `calls`. C'est la lecon
 * de TASK-1109.
 */
class LoopArticleCard extends Component
{
    public Loop $loop;

    /** @var array<string, bool> */
    private array $droitsMemo = [];

    public function mount(Loop $loop): void
    {
        $this->loop = $loop;
    }

    private function droit(string $capacite): bool
    {
        return $this->droitsMemo[$capacite] ??= $this->resolver()->can(auth()->user(), $this->loop, $capacite);
    }

    private function forgetDroits(): void
    {
        $this->droitsMemo = [];
    }

    public function canView(): bool
    {
        return $this->droit('writing.view');
    }

    public function canCompose(): bool
    {
        return $this->droit('writing.compose');
    }

    /**
     * Le lien pour reprendre un brouillon, **scope sur l'Organization de la
     * Boucle**.
     *
     * Les routes nues retombent sur l'Organization par defaut : c'est le
     * bloquant trouve sur la Card Demande-Offre, ou « Creer une Offre »
     * enregistrait dans une autre Organization. Le slug vient de la Boucle,
     * jamais de la requete.
     */
    public function editUrl(BlogPost $article): string
    {
        $slug = $this->loop->organization?->slug;

        return $slug
            ? route('organization.blog.edit', ['organization' => $slug, 'post' => $article->slug])
            : route('blog.edit', ['post' => $article->slug]);
    }

    public function readUrl(BlogPost $article): string
    {
        $slug = $this->loop->organization?->slug;

        return $slug
            ? route('organization.blog.show', ['organization' => $slug, 'post' => $article->slug])
            : route('blog.show', ['post' => $article->slug]);
    }

    /**
     * Le Dossier de la Boucle — la ou l'on ecrit.
     *
     * `dossiers.show` **n'existe que scopee** par Organization : la route nue
     * n'est pas declaree. Le slug vient de la Boucle, jamais de la requete.
     */
    public function dossierUrl(\App\Models\Dossier $dossier): ?string
    {
        $slug = $this->loop->organization?->slug;

        return $slug
            ? route('organization.dossiers.show', ['organization' => $slug, 'dossier' => $dossier])
            : null;
    }

    private function service(): LoopWritingService
    {
        return app(LoopWritingService::class);
    }

    private function resolver(): LoopPermissionResolver
    {
        return app(LoopPermissionResolver::class);
    }

    public function render()
    {
        $this->forgetDroits();

        $canView = $this->canView();
        $utilisateur = auth()->user();

        $vue = view('livewire.loop-article-card', [
            'canView' => $canView,
            'canCompose' => $canView && $this->canCompose(),
            'dossier' => $canView ? $this->service()->dossierFor($this->loop) : null,
            'myDrafts' => $canView && $utilisateur ? $this->service()->myDrafts($this->loop, $utilisateur) : collect(),
            'othersDrafts' => $canView && $utilisateur ? $this->service()->othersDrafts($this->loop, $utilisateur) : collect(),
            'published' => $canView ? $this->service()->published($this->loop) : collect(),
            'series' => $canView ? $this->service()->series($this->loop) : collect(),
            'pendingCoAuthors' => $canView ? $this->service()->pendingCoAuthors($this->loop) : collect(),
        ]);

        $this->forgetDroits();

        return $vue;
    }
}
