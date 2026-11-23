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
     * Les liens sortants, **precalcules et jamais exposes**.
     *
     * La premiere version en faisait trois methodes publiques prenant un
     * modele. Livewire expose toute methode publique comme action et resout son
     * argument par **liaison implicite** — donc sans le moindre lien avec cette
     * Boucle. On pouvait leur passer le slug d'un brouillon d'une autre
     * Organization : la methode rendait une URL valide, et un slug inconnu
     * levait une exception. Les deux reponses se distinguent — un oracle
     * d'existence a l'echelle de la plateforme, les slugs etant devinables.
     *
     * **Troisieme fois dans cette serie.** Les URL sont desormais construites
     * ici, pour les seuls objets que la Card a elle-meme charges, et passees a
     * la vue comme des chaines.
     *
     * Le slug d'Organization vient de la **Boucle**, jamais de la requete : les
     * routes nues retombent sur l'Organization par defaut.
     */
    private function lien(string $route, array $parametres): string
    {
        $slug = $this->loop->organization?->slug;

        return $slug
            ? route('organization.'.$route, array_merge(['organization' => $slug], $parametres))
            : route($route, $parametres);
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

        $dossier = $canView ? $this->service()->dossierFor($this->loop) : null;

        /* TASK-1872 correctif 04 — la Card ne propose que ce que le serveur
           acceptera.
           ====================================================
           Trois conditions, et chacune evite un bouton menteur :
           la capacite `writing.compose` ; une Boucle VIVANTE, puisque
           `DossierPolicy::createArticle()` refuse l'archivee avant tout le
           reste ; et, quand le Dossier racine existe deja, la policy
           elle-meme — la meme porte, interrogee de la meme facon que par le
           controleur.

           Quand il n'existe PAS encore, il n'y a rien a soumettre a la
           policy : c'est `ensureRootDossier()` qui le creera au premier
           envoi. On s'en tient alors aux deux premieres conditions, et le
           serveur reste l'arbitre. */
        $canCompose = $canView
            && $this->canCompose()
            && ! $this->loop->isArchived()
            && ($dossier === null || ($utilisateur !== null && $utilisateur->can('createArticle', $dossier)));

        $brouillons = $canView && $utilisateur
            ? $this->service()->myDrafts($this->loop, $utilisateur)
            : collect();

        $publies = $canView ? $this->service()->published($this->loop) : collect();
        $ailleurs = $canView && $utilisateur ? $this->service()->othersDrafts($this->loop, $utilisateur) : collect();

        // TASK-1884 #3 — « Modifier » pour l'auteur, les co-auteurs, le
        // proprietaire et les animateurs ; une Boucle archivee ne s'ecrit plus.
        $modifiables = $this->loop->isArchived()
            ? []
            : $this->service()->editableArticleIds($this->loop, $utilisateur, $publies->concat($ailleurs));

        $vue = view('livewire.loop-article-card', [
            'canView' => $canView,
            'canCompose' => $canCompose,
            'dossier' => $dossier,
            'myDrafts' => $brouillons,
            // Les URL des seuls objets que la Card a elle-meme charges.
            'editUrls' => $brouillons->mapWithKeys(
                fn (BlogPost $a) => [$a->id => $this->lien('blog.edit', ['post' => $a->slug])],
            ),
            'othersDrafts' => $ailleurs,
            'modifyUrls' => $publies->concat($ailleurs)
                ->filter(fn (BlogPost $a) => in_array((string) $a->id, $modifiables, true))
                ->mapWithKeys(fn (BlogPost $a) => [$a->id => $this->lien('blog.edit', ['post' => $a->slug])]),
            'published' => $publies,
            'readUrls' => $publies->mapWithKeys(
                fn (BlogPost $a) => [$a->id => $this->lien('blog.show', ['post' => $a->slug])],
            ),
            'dossierUrl' => $dossier ? $this->lien('dossiers.show', ['dossier' => $dossier]) : null,
            /* TASK-1872 correctif 04 — « + Ecrire un Article » MENAIT au
               Dossier.
               =================================================
               Constat de Cyril, 21/11 : « le bouton pointe vers Dossiers,
               alors qu'il devrait afficher le formulaire de saisie de
               l'article ». Il pointait en effet sur `dossierUrl` — un detour
               par un ecran entier pour un geste qui tient en un titre, et une
               porte fermee au bout pour un membre.

               La Card ne refait toujours pas l'editeur : elle poste vers LA
               MEME route que la Card Dossiers
               (`organization.loops.dossier.articles.store`), laquelle cree le
               brouillon deja lie au Dossier racine et a la Boucle, puis ouvre
               l'editeur Blog existant. Un seul parcours d'ecriture, deux
               portes d'entree. */
            'writeUrl' => $this->lien('loops.dossier.articles.store', ['loop' => $this->loop]),
            'series' => $canView ? $this->service()->series($this->loop) : collect(),
            'pendingCoAuthors' => $canView ? $this->service()->pendingCoAuthors($this->loop) : collect(),
        ]);

        $this->forgetDroits();

        return $vue;
    }
}
