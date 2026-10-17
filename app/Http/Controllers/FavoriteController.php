<?php

namespace App\Http\Controllers;

use App\Models\Favorite;
use App\Models\Organization;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FavoriteController extends Controller
{
    public function index(): View
    {
        $favorites = auth()->user()->favorites()
            ->with(['service.user', 'service.category', 'service.skills'])
            ->latest('created_at')
            ->paginate(15);

        return view('favorites.index', compact('favorites'));
    }

    /**
     * TASK-1685 (P0-1) — ECRITURE CROSS-TENANT, prouvee puis fermee.
     *
     * ## Ce qui a ete mesure, en base isolee
     *
     * Un membre de l'Organization A postait sur
     * `/org/B/favorites/{service de B}/toggle` et la table `favorites` passait
     * de 0 a 1, avec la ligne
     * `{user_id: <membre de A>, service_id: <service de B>, organization_id: <B>}`.
     *
     * ## La cause
     *
     * `Service $service` arrive par liage implicite de route, et RIEN ne
     * confrontait son Organization a celle de l'utilisateur. Le controleur
     * ecrivait ensuite `organization_id => $service->organization_id` : il
     * inscrivait donc fidelement la ligne dans le tenant ETRANGER. Ce n'etait
     * pas une garde trop faible, c'etait une garde absente.
     *
     * ## La garde, en deux temps — et pourquoi deux
     *
     * 1. **Le service doit appartenir a l'Organization de l'utilisateur.**
     *    C'est l'invariant de fond, et il ne depend d'aucune URL : il ferme
     *    A -> service de B comme B -> service de A.
     *
     * 2. **Quand l'URL porte une Organization, le service doit etre la sienne.**
     *    Ferme le cas « URL de A, service de B » : un chemin coherent ne doit
     *    pas pouvoir agir hors de l'espace qu'il nomme.
     *
     * Le second predicat lit le PARAMETRE DE ROUTE, jamais `currentOrganization()`.
     * La difference est mesurable : sur la forme NON prefixee
     * (`/favorites/{service}/toggle`), `currentOrganization()` rend
     * l'Organization par defaut de la plateforme, qui n'est pas celle de
     * l'utilisateur — s'en servir refuserait un geste parfaitement legitime a
     * tout membre d'une autre Organization. Le parametre, lui, est simplement
     * absent sur cette forme, donc le predicat ne s'applique pas.
     *
     * ## Le SuperAdmin ne passe PAS
     *
     * Aucune exemption `is_admin` ici, et c'est delibere : doctrine TASK-1658,
     * « inspecter n'est pas participer ». Mettre en favori est un geste de
     * MEMBRE, pas une lecture de supervision.
     *
     * ## 404 et pas 403
     *
     * Le refus ne doit rien confirmer de l'existence du service etranger —
     * meme doctrine que TASK-1479 sur l'annuaire et T1601-T1606 sur le tenant.
     *
     * ## Le chemin de SUPPRESSION etait deja sur
     *
     * Le `where('user_id', $user->id)` ci-dessous borne la bascule aux lignes
     * de l'appelant : personne n'a jamais pu supprimer le favori d'autrui. La
     * garde est posee AVANT de toucher quoi que ce soit, donc ce chemin reste
     * inaccessible a un etranger.
     */
    public function toggle(Request $request, Service $service): JsonResponse|RedirectResponse
    {
        $user = auth()->user();

        abort_unless($user !== null && $service->organization_id === $user->organization_id, 404);

        $urlOrganization = $request->route('organization') ?? $request->route('community');

        if ($urlOrganization !== null) {
            $slug = $urlOrganization instanceof Organization ? $urlOrganization->slug : (string) $urlOrganization;

            abort_unless($slug === $service->organization?->slug, 404);
        }

        $existing = Favorite::where('user_id', $user->id)
            ->where('service_id', $service->id)
            ->where('organization_id', $service->organization_id)
            ->first();

        if ($existing) {
            $existing->delete();
            $favorited = false;
        } else {
            Favorite::create([
                'user_id' => $user->id,
                'service_id' => $service->id,
                'organization_id' => $service->organization_id,
            ]);
            $favorited = true;
        }

        if ($request->wantsJson()) {
            return response()->json(['favorited' => $favorited]);
        }

        return back()->with('success', __($favorited ? 'favorites.added' : 'favorites.removed'));
    }
}
