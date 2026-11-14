<?php

namespace App\Http\Controllers;

use App\Support\Blog\LoopArticleLinks;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * TASK-1827 — catalogue premium des Articles de blog lies a une Boucle.
 *
 * Reutilise exclusivement ce qui existe deja : `BlogPost::scopePublished()`
 * (meme regle de lecture que `BlogController::index()`) et
 * `LoopArticleLinks::catalogQuery()` (meme definition de « lie a une Boucle
 * accessible » que la bannière de `blog/show.blade.php` et le badge de
 * `blog/index.blade.php`). Aucun nouveau systeme de relation.
 *
 * L'acces a cette page est deja garanti par le groupe de routes `/loops`
 * (`auth` + `organization.member` + `loops.enabled`) — exactement le meme
 * perimetre que `loops.index`, pas un perimetre invente ici.
 */
class LoopArticlesController extends Controller
{
    public function index(Request $request, LoopArticleLinks $loopArticleLinks): View
    {
        $organization = currentOrganization();

        if (! $organization) {
            abort(404);
        }

        $selectedLoopId = $request->query('loop');

        $posts = $loopArticleLinks->catalogQuery($organization, $selectedLoopId)
            ->with(['user', 'category'])
            ->withCount(['comments', 'likes'])
            ->latest('published_at')
            ->paginate(12)
            ->withQueryString();

        $linkedLoopsByPost = $loopArticleLinks->forPosts($posts->getCollection(), $organization);

        $filterOptions = $loopArticleLinks->filterOptions($organization);

        // Un `?loop=` qui ne designe aucune Boucle active de cette
        // Organization est deja ignore par `catalogQuery()` (resultat
        // identique a l'absence de filtre) : on ne marque donc le chip actif
        // que s'il correspond reellement a une option proposee, jamais une
        // valeur fabriquee.
        $activeLoopId = $filterOptions->contains('id', $selectedLoopId) ? $selectedLoopId : null;

        return view('loops.articles', [
            'posts' => $posts,
            'linkedLoopsByPost' => $linkedLoopsByPost,
            'filterOptions' => $filterOptions,
            'activeLoopId' => $activeLoopId,
        ]);
    }
}
