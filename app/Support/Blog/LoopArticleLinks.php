<?php

namespace App\Support\Blog;

use App\Models\BlogPost;
use App\Models\Loop;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1827 — l'autorite UNIQUE de la relation Article -> Boucles ACCESSIBLES.
 *
 * Ne cree aucune nouvelle relation : elle lit les deux chemins deja ecrits par
 * `BlogPost::linkedLoops()` (TASK-1826) — pivot `blog_post_loop` et Dossier
 * racine d'une Boucle — mais les resout GROUPES (anti N+1 sur une page
 * paginee) et les filtre a ce qu'une Boucle visible dans le catalogue montre
 * deja : meme Organization, statut `active`. Une Boucle archivee n'est
 * independamment decouvrable nulle part (`LoopController::show()`) : elle ne
 * doit donc jamais apparaitre ici non plus.
 *
 * Ne decide PAS de la lecture de l'article lui-meme (ca reste
 * `BlogController`/`scopePublished()`), seulement de QUELLES Boucles
 * mentionner autour de lui, et seulement pour un lecteur authentifie de la
 * MEME Organization (un visiteur public ou d'une autre Organization n'a
 * aucun chemin existant vers le catalogue des Boucles : lui montrer un nom de
 * Boucle ici serait une fuite neuve, pas une reprise d'un contrat existant).
 */
class LoopArticleLinks
{
    /**
     * Les Boucles accessibles liees a UN article deja charge. Cardinalite
     * minuscule (un article tient rarement plus de 2-3 Boucles) : reutilise
     * directement `linkedLoops()` sans bulk-resolution.
     *
     * @return Collection<int, Loop>
     */
    public function forPost(BlogPost $post, Organization $organization): Collection
    {
        return $post->linkedLoops()
            ->filter(fn (Loop $loop) => $loop->organization_id === $organization->id && $loop->isActive())
            ->values();
    }

    /**
     * Meme resolution que `forPost()`, mais GROUPEE sur une collection
     * d'articles deja paginee : 2 requetes au total, jamais une par article.
     *
     * @param  Collection<int, BlogPost>  $posts
     * @return Collection<string, Collection<int, Loop>> keyed by post id
     */
    public function forPosts(Collection $posts, Organization $organization): Collection
    {
        return $this->resolveForPostIds($posts->pluck('id'), $organization);
    }

    /**
     * La requete de base du catalogue « Articles de boucle » : articles
     * publies, listes dans le Blog (meme `scopePublished()` que
     * `BlogController::index()`), lies a au moins une Boucle accessible de
     * cette Organization. `whereHas` => chaque article au plus une fois,
     * quel que soit le nombre de Boucles liees (pas de doublon).
     *
     * `$loopId`, s'il est fourni, n'est applique que s'il designe reellement
     * une Boucle active de CETTE Organization — sinon il est silencieusement
     * ignore (jamais d'erreur ni de resultat vide distinctif, pour ne donner
     * aucun oracle sur l'existence d'une Boucle d'une autre Organization).
     *
     * @return Builder<BlogPost>
     */
    public function catalogQuery(Organization $organization, ?string $loopId = null): Builder
    {
        $validLoopId = $loopId ? $this->validatedLoopId($organization, $loopId) : null;

        return BlogPost::published()
            ->where('organization_id', $organization->id)
            ->where(function (Builder $q) use ($organization, $validLoopId) {
                $q->whereHas('loops', function (Builder $q2) use ($organization, $validLoopId) {
                    $q2->where('loops.organization_id', $organization->id)->where('loops.status', 'active');
                    if ($validLoopId) {
                        $q2->where('loops.id', $validLoopId);
                    }
                })->orWhereHas('dossierEntry.dossier.loop', function (Builder $q2) use ($organization, $validLoopId) {
                    $q2->where('loops.organization_id', $organization->id)->where('loops.status', 'active');
                    if ($validLoopId) {
                        $q2->where('loops.id', $validLoopId);
                    }
                });
            });
    }

    /**
     * Les Boucles proposables dans le filtre du catalogue : seulement celles
     * qui portent reellement au moins un article du catalogue (jamais une
     * option qui viderait la liste).
     *
     * @return Collection<int, Loop>
     */
    public function filterOptions(Organization $organization): Collection
    {
        $postIds = $this->catalogQuery($organization)->pluck('id');

        if ($postIds->isEmpty()) {
            return collect();
        }

        return $this->resolveForPostIds($postIds, $organization)
            ->flatten(1)
            ->unique('id')
            ->sortBy(fn (Loop $loop) => $loop->name)
            ->values();
    }

    private function validatedLoopId(Organization $organization, string $loopId): ?string
    {
        return Loop::where('id', $loopId)
            ->where('organization_id', $organization->id)
            ->where('status', 'active')
            ->value('id');
    }

    /**
     * @param  Collection<int, string>  $postIds
     * @return Collection<string, Collection<int, Loop>>
     */
    private function resolveForPostIds(Collection $postIds, Organization $organization): Collection
    {
        if ($postIds->isEmpty()) {
            return collect();
        }

        $pivotRows = DB::table('blog_post_loop')
            ->whereIn('blog_post_id', $postIds)
            ->get(['blog_post_id', 'loop_id']);

        $dossierRows = DB::table('dossier_blog_posts')
            ->join('dossiers', 'dossiers.id', '=', 'dossier_blog_posts.dossier_id')
            ->whereIn('dossier_blog_posts.blog_post_id', $postIds)
            ->whereNotNull('dossiers.loop_id')
            ->get(['dossier_blog_posts.blog_post_id as blog_post_id', 'dossiers.loop_id as loop_id']);

        $loopIds = $pivotRows->pluck('loop_id')->merge($dossierRows->pluck('loop_id'))->unique();

        $loops = Loop::whereIn('id', $loopIds)
            ->where('organization_id', $organization->id)
            ->where('status', 'active')
            ->get()
            ->keyBy('id');

        $map = [];

        foreach ($pivotRows->merge($dossierRows) as $row) {
            if ($loop = $loops->get($row->loop_id)) {
                $map[$row->blog_post_id][$loop->id] = $loop;
            }
        }

        return collect($map)->map(fn (array $loopsById) => collect(array_values($loopsById)));
    }
}
