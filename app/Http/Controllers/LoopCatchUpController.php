<?php

namespace App\Http\Controllers;

use App\Models\Loop;
use App\Models\Organization;
use App\Support\Loops\LoopCatchUpDigest;
use App\Support\Loops\LoopCatchUpWindow;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * TASK-1476 — l'ecran « Rattrape-moi depuis… » d'UNE Boucle.
 *
 * ## Aucune seconde autorite d'acces
 *
 * Le droit de lire l'espace de travail d'une Boucle existe deja et vit dans
 * `LoopPolicy::viewWorkspace` : membre ACTIF, meme Organization, compte non
 * desactive. Ce controleur l'appelle ; il n'en ecrit pas une variante.
 *
 * C'est volontairement la regle la PLUS stricte du depot : `LoopController::show`
 * accorde en plus un acces a la Boucle principale de l'Organization. Un
 * rattrapage n'est pas une page de presentation — il rapporte le contenu — donc
 * il s'aligne sur `viewWorkspace` et rien d'autre.
 *
 * ## Rien n'est ecrit
 *
 * `GET` sans effet de bord : ni position de lecture, ni notification, ni
 * mutation d'objet metier. Relire dix fois le meme rattrapage laisse la base
 * exactement dans le meme etat.
 */
class LoopCatchUpController extends Controller
{
    public function __construct(private readonly LoopCatchUpDigest $digest) {}

    public function __invoke(Request $request, Loop|Organization|string $loopOrOrganization, ?Loop $loop = null): View
    {
        $loop = $this->resolveRouteLoop($loopOrOrganization, $loop);
        $organization = $this->resolveOrganization();
        $user = $request->user();

        // Frontiere de tenant, dans les deux sens : l'utilisateur appartient a
        // l'Organization courante, et la Boucle aussi.
        if ($user->organization_id !== $organization->id || $loop->organization_id !== $organization->id) {
            abort(404);
        }

        // L'autorite unique. Un non-membre n'obtient pas un rattrapage degrade :
        // il n'en obtient aucun.
        $this->authorize('viewWorkspace', $loop);

        $window = LoopCatchUpWindow::fromRequest($request);
        $sections = $this->digest->for($loop, $window, $user);

        return view('loops.catch-up', [
            'organization' => $organization,
            'loop' => $loop,
            'window' => $window,
            'sections' => $sections,
            'isEmpty' => $this->digest->isEmpty($sections),
        ]);
    }

    private function resolveRouteLoop(Loop|Organization|string $loopOrOrganization, ?Loop $loop = null): Loop
    {
        if ($loopOrOrganization instanceof Loop) {
            return $loopOrOrganization;
        }

        if ($loop instanceof Loop) {
            return $loop;
        }

        abort(404);
    }

    private function resolveOrganization(): Organization
    {
        $organization = CurrentOrganization::get();

        if ($organization instanceof Organization) {
            return $organization;
        }

        $organization = auth()->user()?->organization;

        if (! $organization instanceof Organization) {
            abort(404);
        }

        return $organization;
    }
}
