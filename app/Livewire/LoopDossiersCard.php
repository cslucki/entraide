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
use Livewire\Attributes\On;
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

    /**
     * TASK-1828 — navigation contextuelle depuis ChatLoop.
     *
     * Meme idiome que `LoopRoadmapCard::$detailId`/`openDetailFromChat()`
     * (TASK-1826) : un clic sur l'apercu Article/Fichier dans le chat ouvre
     * cette Card ET y pose l'identifiant cible, via un SECOND evenement
     * Livewire en parallele du `bp-open-loop-card` Alpine (qui ne transporte
     * que le nom de la Card). Ces trois proprietes ne sont JAMAIS une
     * permission : `render()` les REVALIDE a chaque passage (voir
     * `resolveDetailFile()`/`resolveDetailArticle()`).
     */
    public array $highlightedFileIds = [];

    public ?string $detailFileId = null;

    public ?string $detailArticleId = null;

    public function mount(Loop $loop): void
    {
        $this->loop = $loop;
    }

    /**
     * TASK-1828 — un depot depuis ChatLoop annonce 1+ fichiers du MEME
     * geste (`metadata.file_ids`). Un seul : ouverture directe de son
     * apercu. Plusieurs : la Card montre une selection bornee a CES
     * fichiers-la (pas la liste entiere), pour que la personne choisisse.
     */
    #[On('open-dossier-file-detail')]
    public function openFileDetailFromChat(array $ids): void
    {
        $this->detailArticleId = null;
        $this->highlightedFileIds = array_values(array_unique(array_filter($ids)));
        $this->detailFileId = count($this->highlightedFileIds) === 1 ? $this->highlightedFileIds[0] : null;
    }

    #[On('open-dossier-article-detail')]
    public function openArticleDetailFromChat(string $id): void
    {
        $this->highlightedFileIds = [];
        $this->detailFileId = null;
        $this->detailArticleId = $id;
    }

    /** Choisir UN fichier parmi la selection bornee d'un depot multiple. */
    public function selectHighlightedFile(string $id): void
    {
        if (in_array($id, $this->highlightedFileIds, true)) {
            $this->detailFileId = $id;
        }
    }

    /** Quitter l'apercu d'UN fichier pour revenir a la selection du depot. */
    public function backToSelection(): void
    {
        $this->detailFileId = null;
    }

    /**
     * Retour a la liste normale de la Card — PAS la fermeture de la Card
     * elle-meme (`closeCard()` Alpine, dans `loops/show.blade.php`, reste
     * inchangee). Deux fermetures distinctes, meme principe que Roadmap.
     */
    public function closeDetail(): void
    {
        $this->detailFileId = null;
        $this->detailArticleId = null;
        $this->highlightedFileIds = [];
    }

    /**
     * TASK-1684 — se remettre a jour apres un depot fait depuis la ChatLoop.
     *
     * Le depot du menu « + » du composeur passe par l'endpoint Dossiers en
     * `fetch` direct, hors du cycle Livewire : cette Card, deja montee, n'en
     * savait rien et continuait d'afficher son dernier rendu. Le membre devait
     * aller sur l'ecran du Dossier puis revenir pour voir son fichier —
     * exactement ce qui a ete rapporte en recette.
     *
     * Le corps est VIDE a dessein : `render()` recalcule compte et recents a
     * chaque passage. Ce qui manquait n'etait pas un rechargement de donnees,
     * c'etait une raison de re-rendre. Rien n'est duplique ici.
     */
    #[On('bp-dossier-fichier-depose')]
    public function rafraichirApresDepot(): void {}

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
            // Les deux gestes rapides restent dans la Boucle : un brouillon
            // pre-lie (Dossier racine + Boucle) qui ouvre l'editeur Blog, et
            // le depot vers l'endpoint d'upload existant du Dossier — meme
            // backend, memes policies, aucun second stockage.
            'write' => route('organization.loops.dossier.articles.store', [
                'organization' => $organization,
                'loop' => $this->loop,
            ]),
            'upload' => route('organization.dossiers.files.store', [
                'organization' => $organization,
                'dossier' => $dossier,
            ]),
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

        // TASK-1828 — revalidee ICI, a CHAQUE rendu, que la Card soit
        // autorisee ou non : un id pose par un clic ChatLoop sur une
        // Boucle devenue inaccessible ne doit jamais resoudre quoi que ce
        // soit (meme sans Dossier/droit de le voir).
        $detailFile = $canView ? $this->resolveDetailFile($this->detailFileId) : null;
        $highlightedFiles = $canView ? $this->resolveHighlightedFiles($this->highlightedFileIds) : collect();
        $detailArticle = $canView ? $this->resolveDetailArticle($this->detailArticleId) : null;
        $detailFileUrls = $detailFile ? $this->fileDetailUrls($detailFile, $dossier) : null;
        $detailArticleUrl = $detailArticle ? ($this->articleUrls(collect([$detailArticle]))[$detailArticle->id] ?? null) : null;

        if (! $canView) {
            return view('livewire.loop-dossiers-card', [
                'dossier' => null,
                'urls' => null,
                'articles' => collect(),
                'articleUrls' => collect(),
                'series' => collect(),
                'files' => collect(),
                // Les deux sorties de `render()` offrent le meme contrat.
                'fileUrls' => [],
                'total' => 0,
                'canCreateArticle' => false,
                'canUploadFile' => false,
                'readOnly' => true,
                'detailFile' => null,
                'detailFileUrls' => null,
                'highlightedFiles' => collect(),
                'detailArticle' => $detailArticle,
                'detailArticleUrl' => $detailArticleUrl,
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

        // TASK-1684 : `uploadFiles`, pas `manageFiles`. Le MASTER a ouvert le
        // DEPOT a tout membre actif ; `manageFiles` gouverne l'ADMINISTRATION
        // des fichiers (renommer, deplacer, markdown) et serait ici une porte
        // plus etroite que celle du serveur — la Card cacherait un geste que
        // `StoreDossierFileRequest` accepte. Meme correction que la tuile du
        // menu « + » du composeur.
        $canUploadFile = ! $readOnly
            && $resolver->can($user, $this->loop, 'dossiers.upload_file')
            && $user->can('uploadFiles', $dossier);

        $articles = $this->recentArticles($dossier);

        return view('livewire.loop-dossiers-card', [
            'dossier' => $dossier,
            'urls' => $this->dossierUrls($dossier),
            'articles' => $articles,
            'articleUrls' => $this->articleUrls($articles),
            'series' => $this->recentSeries($dossier),
            'files' => $fichiersRecents = $this->recentFiles($dossier),
            'fileUrls' => $this->fileUrls($fichiersRecents, $dossier),
            'total' => $this->totalItems($dossier),
            'canCreateArticle' => $canCreateArticle,
            'canUploadFile' => $canUploadFile,
            'readOnly' => $readOnly,
            'detailFile' => $detailFile,
            'detailFileUrls' => $detailFileUrls,
            'highlightedFiles' => $highlightedFiles,
            'detailArticle' => $detailArticle,
            'detailArticleUrl' => $detailArticleUrl,
        ]);
    }

    /**
     * TASK-1828 — le fichier ouvert depuis ChatLoop, REVALIDE a chaque
     * rendu. Passer un id a la Card n'accorde JAMAIS une permission : seule
     * `viewFiles`, relue ici sur le fichier reel, decide (meme ability que
     * `LoopChat::projectedDossierFiles()`, T1826 — `DossierFilePolicy`,
     * qui gere deja partage nominatif, partage Boucle et suppression
     * douce).
     */
    private function resolveDetailFile(?string $id): ?DossierFile
    {
        if (! $id) {
            return null;
        }

        $user = auth()->user();

        $file = DossierFile::query()
            ->where('organization_id', $this->loop->organization_id)
            ->with('dossier')
            ->find($id);

        if (! $file || ! $user || ! $user->can('viewFiles', $file)) {
            return null;
        }

        return $file;
    }

    /**
     * Meme revalidation que `resolveDetailFile()`, pour l'ensemble borne
     * d'un depot multiple. Un id invalide/inaccessible est silencieusement
     * absent du resultat — jamais une erreur, jamais une fuite.
     *
     * @param  array<int, string>  $ids
     * @return Collection<int, DossierFile>
     */
    private function resolveHighlightedFiles(array $ids): Collection
    {
        if (empty($ids)) {
            return collect();
        }

        $user = auth()->user();

        if (! $user) {
            return collect();
        }

        return DossierFile::query()
            ->where('organization_id', $this->loop->organization_id)
            ->whereIn('id', $ids)
            ->with('dossier')
            ->get()
            ->filter(fn (DossierFile $file) => $user->can('viewFiles', $file))
            ->values();
    }

    /**
     * TASK-1828 — l'Article ouvert depuis ChatLoop, REVALIDE a chaque
     * rendu. Meme perimetre que `LoopChat::projectedArticles()` (T1826) :
     * publie, liste dans le Blog, meme Organization que la Boucle. Le
     * Manifeste d'une Boucle est structurellement hors de ce perimetre
     * (`listed_in_blog = false`, exclu par `scopePublished()`) — aucune
     * garde supplementaire n'est necessaire ici.
     */
    private function resolveDetailArticle(?string $id): ?BlogPost
    {
        if (! $id) {
            return null;
        }

        return BlogPost::published()
            ->where('organization_id', $this->loop->organization_id)
            ->with('user')
            ->find($id);
    }

    /**
     * Les URL d'apercu et de telechargement d'UN fichier precis, ouvert
     * depuis ChatLoop — meme distinction « depose vs partage » que
     * `fileUrls()` juste au-dessus, generalisee a un fichier hors de la
     * liste des fichiers recents.
     *
     * @return array{preview: ?string, download: ?string}
     */
    private function fileDetailUrls(DossierFile $file, ?Dossier $dossier): array
    {
        $organization = $this->loop->organization?->slug;

        if (! $organization) {
            return ['preview' => null, 'download' => null];
        }

        $partage = ! $dossier || $file->dossier_id !== $dossier->id;

        if ($partage) {
            return [
                'preview' => Route::has('organization.dossiers.shared.preview')
                    ? route('organization.dossiers.shared.preview', ['organization' => $organization, 'file' => $file->id])
                    : null,
                'download' => Route::has('organization.dossiers.shared.show')
                    ? route('organization.dossiers.shared.show', ['organization' => $organization, 'file' => $file->id])
                    : null,
            ];
        }

        return [
            'preview' => route('organization.dossiers.files.preview', [
                'organization' => $organization,
                'dossier' => $file->dossier_id,
                'file' => $file->id,
            ]),
            'download' => route('organization.dossiers.files.show', [
                'organization' => $organization,
                'dossier' => $file->dossier_id,
                'file' => $file->id,
            ]),
        ];
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
        // TASK-1695 (recette de Cyril) — les fichiers PARTAGES avec la Boucle
        // sont dans la MEME liste que les fichiers deposes : « ce ne sont pas
        // des fichiers a part ». Une seconde tuile donnait a croire a un
        // second type de partage.
        return \App\Models\DossierFile::query()
            ->withoutTrashed()
            ->where('organization_id', $dossier->organization_id)
            ->where(function ($q) use ($dossier) {
                $q->where('dossier_id', $dossier->id);

                if ($dossier->isLoopDossier()) {
                    $q->orWhereHas('loopShares', fn ($s) => $s
                        ->where('loop_id', $dossier->loop_id)
                        ->where('organization_id', $dossier->organization_id));
                }
            })
            ->with('uploader:id,name,first_name,avatar,banned_at')
            ->latest('created_at')
            ->limit(self::RECENT)
            ->get();
    }

    /**
     * TASK-1695 (5a, recette de Cyril) — les fichiers PARTAGES avec la Boucle.
     *
     * Distincts des fichiers DEPOSES : ils vivent chez leur proprietaire, qui
     * peut retirer le partage a tout moment. Les melanger a `recentFiles()`
     * laisserait croire qu'ils appartiennent au Dossier de la Boucle.
     *
     * Aucune garde ici : cette carte ne s'affiche qu'a qui peut deja voir le
     * Dossier de la Boucle, et le partage vise la Boucle entiere.
     */
    /**
     * Les URL de lecture des fichiers partages, construites DANS LE COMPOSANT.
     *
     * TASK-1695 — 500 en recette (Cyril, 24/10) : la vue faisait
     * `$loop->organization?->slug`, mais `$loop` n'y est PAS le modele —
     * `Undefined property: stdClass::$organization`. `$this->loop` l'est, ici.
     *
     * C'est exactement l'idiome de `dossierUrls()` juste au-dessus, et la
     * lecon TASK-1103 qu'il porte : **la Boucle d'abord, la requete jamais** —
     * une interaction Livewire arrive sur `POST /livewire/update`, qui ne
     * porte aucun parametre `organization`.
     *
     * @return array<string, string> id du fichier => URL d'apercu
     */
    /**
     * TASK-1697 (4) — chaque fichier recent devient CLIQUABLE.
     *
     * Signale par Cyril : la liste nommait les fichiers sans permettre de les
     * ouvrir. Il fallait passer par « Ouvrir » et traverser tout le Dossier
     * pour consulter un document qu'on voyait deja.
     *
     * ## Deux routes, parce que deux natures
     *
     * Depuis T1695 la liste mele les fichiers DEPOSES dans le Dossier et ceux
     * PARTAGES avec la Boucle. Les premiers s'ouvrent par le chemin du
     * Dossier ; les seconds n'y sont pas — leur chemin rendrait 404 — et
     * passent par la route dediee.
     *
     * On choisit par ligne, pas par liste : `dossier_id` dit laquelle.
     *
     * ## Construites DANS le composant
     *
     * `$loop` n'est pas le modele dans la vue (stdClass) — 500 en recette le
     * 24/10. `$this->loop` l'est, ici. Et « la Boucle d'abord, la requete
     * jamais » (T1103) : une interaction Livewire arrive sur
     * `POST /livewire/update`, qui ne porte aucun parametre `organization`.
     *
     * @return array<string, string> id du fichier => URL d'ouverture
     */
    private function fileUrls(Collection $fichiers, Dossier $dossier): array
    {
        $organization = $this->loop->organization?->slug;

        if (! $organization) {
            return [];
        }

        return $fichiers->mapWithKeys(function (\App\Models\DossierFile $f) use ($organization, $dossier) {
            $partage = $f->dossier_id !== $dossier->id;

            $url = $partage
                ? (Route::has('organization.dossiers.shared.preview')
                    ? route('organization.dossiers.shared.preview', ['organization' => $organization, 'file' => $f->id])
                    : null)
                : route('organization.dossiers.files.preview', [
                    'organization' => $organization,
                    'dossier' => $dossier,
                    'file' => $f->id,
                ]);

            return $url ? [$f->id => $url] : [];
        })->all();
    }

    private function sharedFiles(Dossier $dossier): Collection
    {
        return \App\Models\DossierFile::query()
            ->withoutTrashed()
            ->where('organization_id', $dossier->organization_id)
            ->whereHas('loopShares', fn ($q) => $q
                ->where('loop_id', $dossier->loop_id)
                ->where('organization_id', $dossier->organization_id))
            ->with(['loopShares' => fn ($q) => $q->where('loop_id', $dossier->loop_id),
                'loopShares.sharedBy:id,name,first_name,avatar,banned_at,organization_id'])
            ->latest('updated_at')
            ->limit(self::RECENT)
            ->get();
    }
}
