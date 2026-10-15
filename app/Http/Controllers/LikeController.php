<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Models\Like;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LikeController extends Controller
{
    public function toggle(Request $request): JsonResponse
    {
        $request->validate([
            'likeable_type' => 'required|in:blog_post',
            'likeable_id' => 'required|uuid',
        ]);

        $org = $this->resolveOrganization($request);

        if (! $org) {
            return response()->json(['error' => 'No organization context'], 400);
        }

        // TASK-1685 (P0-2) — ECRITURE CROSS-TENANT, prouvee puis fermee.
        //
        // ## Ce qui a ete mesure, en base isolee
        //
        // Un membre de l'Organization A postait sur `/org/B/likes/toggle` avec
        // l'identifiant d'un article de B : HTTP **200**, table `likes` 0 -> 1
        // (`organization_id: B`), et reponse `{"liked":true,"count":1}`.
        // L'article vise portait `audience=loop, listed_in_blog=false` — il
        // n'etait donc PAS public.
        //
        // ## La cause, et pourquoi elle se voyait mal
        //
        // Le scope ci-dessous (`where('organization_id', $org->id)`) est
        // CORRECT — mais il borne l'article a l'Organization de l'URL, pas a
        // celle de l'utilisateur. Autrement dit : le tenant etait pris de
        // l'URL et jamais confronte a l'appartenance. Une lecture rapide
        // voyait un `where` sur `organization_id` et concluait a tort que la
        // frontiere etait tenue.
        //
        // C'est exactement l'incoherence `CurrentOrganization` relevee a
        // l'audit sur les surfaces de LECTURE — ici sur une ECRITURE.
        //
        // ## Pourquoi la garde est posee ICI, avant tout le reste
        //
        // Trois choses fuyaient en aval, et seule une garde en AMONT les ferme
        // toutes : l'ecriture elle-meme ; le COMPTEUR de likes d'un article non
        // public, renvoye dans le corps de la reponse ; et l'ORACLE
        // d'enumeration — 200 contre 404 confirmait l'existence d'un
        // `likeable_id` etranger. Resoudre l'article d'abord, puis refuser,
        // aurait laisse les deux derniers ouverts.
        //
        // Refus en 404, sans corps : rien n'est confirme de l'article vise.
        //
        // AUCUNE exemption `is_admin` : doctrine TASK-1658, « inspecter n'est
        // pas participer ». Aimer un article est un geste de MEMBRE.
        //
        // Ce correctif ne touche PAS les regles de visibilite des articles :
        // au sein de SON Organization, qui pouvait aimer quoi reste inchange.
        abort_unless(auth()->user()?->organization_id === $org->id, 404);

        $modelClass = match ($request->likeable_type) {
            'blog_post' => BlogPost::class,
        };

        $model = $modelClass::where('organization_id', $org->id)
            ->findOrFail($request->likeable_id);

        $existing = Like::where('user_id', auth()->id())
            ->where('likeable_type', $modelClass)
            ->where('likeable_id', $model->id)
            ->where('organization_id', $org->id)
            ->first();

        if ($existing) {
            $existing->delete();
            $liked = false;
        } else {
            Like::create([
                'user_id' => auth()->id(),
                'likeable_type' => $modelClass,
                'likeable_id' => $model->id,
                'organization_id' => $org->id,
            ]);
            $liked = true;
        }

        $count = Like::where('likeable_type', $modelClass)
            ->where('likeable_id', $model->id)
            ->where('organization_id', $org->id)
            ->count();

        return response()->json(['liked' => $liked, 'count' => $count]);
    }

    private function resolveOrganization(Request $request): ?Organization
    {
        $routeOrg = $request->route('organization');

        if ($routeOrg instanceof Organization) {
            return $routeOrg;
        }

        if (is_string($routeOrg) && $routeOrg !== '') {
            return Organization::where('slug', $routeOrg)->first();
        }

        return currentOrganization();
    }
}
