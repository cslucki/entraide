<?php

namespace App\Livewire;

use App\Models\ArticleSeries;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Support\Loops\LoopPermissionResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Livewire\Component;

/**
 * La Card Dossiers d'une Boucle.
 *
 * Cette Card ne cree rien. Toute Boucle possede deja un Dossier racine, pose par
 * LoopRootDocumentService a sa creation et relie par `dossiers.loop_id` ; les
 * Articles, les Series et les fichiers y vivent avec leurs routes, leur policy
 * et leur editeur. La Card est une fenetre sur ce qui existe — pas un second
 * systeme documentaire, pas un Drive parallele.
 *
 * Elle ne charge jamais le Dossier entier : quelques elements recents suffisent
 * a repondre a « qu'est-ce qui a bouge », qui est la question qu'on se pose en
 * l'ouvrant. Le reste est a un clic, sur l'ecran du Dossier.
 *
 * Aucune ecriture ne passe par ici. Ecrire un Article et deposer un fichier
 * renvoient aux parcours existants : les dupliquer aurait garanti qu'ils
 * divergent sur la validation, l'indexation ou les droits.
 */
class LoopDossiersCard extends Component
{
    /** Combien d'elements recents par famille. Une Card n'est pas une liste. */
    private const RECENT = 5;

    private const RECENT_SERIES = 3;

    public Loop $loop;

    public function mount(Loop $loop): void
    {
        $this->loop = $loop;
    }

    /** Le Dossier racine de cette Boucle, ou null si elle n'en a pas encore. */
    public function dossier(): ?Dossier
    {
        return Dossier::where('loop_id', $this->loop->id)->first();
    }

    /**
     * Les routes de l'ecran Dossier, dans l'Organization courante quand il y en
     * a une. Figees au rendu : elles ne dependent d'aucun etat du composant.
     *
     * @return array{open: string, articles: string, files: string}|null
     */
    /**
     * L'adresse d'un Article, **dans l'Organization de la Boucle**.
     *
     * La vue appelait `route('blog.show', $slug)` — la route **nue**, celle qui
     * retombe sur l'Organization par defaut. Mesure sur une Organization non
     * par defaut : deux liens sur cinq pointaient ailleurs. C'est le meme
     * defaut que le bloquant de TASK-1107, signale par TASK-1111.
     *
     * Precalculee dans `render()` et passee comme chaine : une methode publique
     * prenant un modele serait exposee par Livewire comme action, avec liaison
     * implicite — troisieme lecon de la serie.
     */
    private function articleUrls(\Illuminate\Support\Collection $articles): \Illuminate\Support\Collection
    {
        $slug = $this->loop->organization?->slug;

        return $articles->mapWithKeys(fn ($article) => [
            $article->id => $slug
                ? route('organization.blog.show', ['organization' => $slug, 'post' => $article->slug])
                : route('blog.show', ['post' => $article->slug]),
        ]);
    }

    private function dossierUrls(?Dossier $dossier): ?array
    {
        if (! $dossier) {
            return null;
        }

        // **La Boucle d'abord, la requete jamais.** L'ordre inverse laissait le
        // slug de l'URL decider ; c'est la lecon de TASK-1103, ou une
        // interaction Livewire arrive sur `POST /livewire/update`, qui ne porte
        // aucun parametre `organization`.
        $organization = $this->loop->organization?->slug;

        if (! $organization || ! Route::has('organization.dossiers.show')) {
            return null;
        }

        $base = route('organization.dossiers.show', [
            'organization' => $organization,
            'dossier' => $dossier,
        ]);

        // L'ecran du Dossier ouvre son onglet depuis `?tab=` : y pointer reutilise
        // le parcours existant au lieu d'en recopier le formulaire ici.
        return [
            'open' => $base,
            'articles' => $base.'?tab=contenus',
            'files' => $base.'?tab=fichiers',
        ];
    }

    public function render()
    {
        $user = auth()->user();
        $dossier = $this->dossier();
        $resolver = app(LoopPermissionResolver::class);

        $canView = $user
            && $dossier
            && $resolver->can($user, $this->loop, 'dossiers.view')
            && $user->can('view', $dossier);

        if (! $canView) {
            return view('livewire.loop-dossiers-card', [
                'dossier' => null,
                'urls' => null,
                'articles' => collect(),
                'articleUrls' => collect(),
                'series' => collect(),
                'files' => collect(),
                'total' => 0,
                'canCreateArticle' => false,
                'canUploadFile' => false,
                'readOnly' => true,
            ]);
        }

        // Une Boucle archivee se relit et ne s'ecrit plus. Le resolveur le dit
        // deja — seules les capacites marquees `read` traversent l'archivage —
        // et DossierPolicy le redit en passant par LoopPolicy::update. On ne
        // propose donc pas un geste que le serveur refusera.
        $readOnly = $this->loop->isArchived();

        $canCreateArticle = ! $readOnly
            && $resolver->can($user, $this->loop, 'dossiers.create_article')
            && $user->can('update', $dossier);

        $canUploadFile = ! $readOnly
            && $resolver->can($user, $this->loop, 'dossiers.upload_file')
            && $user->can('manageFiles', $dossier);

        $articles = $this->recentArticles($dossier);

        return view('livewire.loop-dossiers-card', [
            'dossier' => $dossier,
            'urls' => $this->dossierUrls($dossier),
            'articles' => $articles,
            'articleUrls' => $this->articleUrls($articles),
            'series' => $this->recentSeries($dossier),
            'files' => $this->recentFiles($dossier),
            'total' => $this->totalItems($dossier),
            'canCreateArticle' => $canCreateArticle,
            'canUploadFile' => $canUploadFile,
            'readOnly' => $readOnly,
        ]);
    }

    /**
     * Le nombre affiche dans l'en-tete et par le configurateur.
     *
     * Articles + fichiers, et rien d'autre : une annexe de Serie est
     * necessairement deja un Article du Dossier — `addAnnex()` l'exige avant de
     * creer son item — donc ajouter les Series compterait deux fois les memes
     * elements.
     */
    private function totalItems(Dossier $dossier): int
    {
        return $dossier->articles()->count() + $dossier->files()->count();
    }

    /** @return Collection<int, BlogPost> */
    private function recentArticles(Dossier $dossier): Collection
    {
        return $dossier->articles()
            ->with('user:id,name,first_name,avatar,banned_at')
            ->reorder()
            ->orderByDesc('blog_posts.updated_at')
            ->limit(self::RECENT)
            ->get();
    }

    /** @return Collection<int, ArticleSeries> */
    private function recentSeries(Dossier $dossier): Collection
    {
        return ArticleSeries::where('dossier_id', $dossier->id)
            ->with(['rootBlogPost:id,title,slug'])
            ->withCount('items')
            ->latest('updated_at')
            ->limit(self::RECENT_SERIES)
            ->get();
    }

    /** @return Collection<int, DossierFile> */
    private function recentFiles(Dossier $dossier): Collection
    {
        return $dossier->files()
            ->with('uploader:id,name,first_name,avatar,banned_at')
            ->latest('created_at')
            ->limit(self::RECENT)
            ->get();
    }
}
