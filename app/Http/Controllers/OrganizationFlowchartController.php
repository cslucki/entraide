<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Support\Flowchart\FlowchartExchanges;
use App\Support\Flowchart\FlowchartGraph;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * TASK-1608 — `/org/{organization}/flowchart`.
 *
 * Une carte interactive qui explique BouclePro ET montre les possibilites
 * reellement praticables dans l'Organization courante.
 *
 * ## Lecture pure
 *
 * Trois lectures, aucune ecriture, aucun appel de provider. Le CTA d'un noeud
 * Boucle mene a `organization.loops.show`, ou les gestes reels vivent deja et
 * ou les Policies sont reevaluees.
 *
 * ## La frontiere d'acces n'est pas inventee ici
 *
 * Elle est celle de la landing (`OrganizationLandingController::__invoke()`),
 * reprise mot pour mot : Organization inactive -> 404 ; Organization non
 * publique et visiteur anonyme -> la page de connexion BORNEE de cette
 * Organization. Arbitrage MASTER : la page reste PUBLIQUE, parce qu'elle
 * explique le produit et sert de porte d'entree graphique.
 *
 * Ce que le visiteur anonyme recoit n'est pas ce que le membre recoit, et la
 * difference n'est pas decidee ici : {@see FlowchartGraph} consomme
 * `VisibleLoops`, qui exige un `User`. Sans identite du tenant visite, la
 * branche des Boucles est vide.
 */
class OrganizationFlowchartController extends Controller
{
    public function __invoke(string $organization, FlowchartGraph $builder): View|RedirectResponse
    {
        $organization = Organization::findBySlug($organization);

        abort_if(! $organization || ! $organization->is_active, 404);

        if (! $organization->is_public && ! auth()->check()) {
            return redirect()->route('organization.login', ['organization' => $organization->slug]);
        }

        $user = auth()->user();

        // Les Propositions et les Demandes ne sont servies qu'a un membre de
        // CETTE Organization. La garde vit dans `FlowchartExchanges`, en
        // premiere ligne de chaque lecture : sans membre, la requete n'est
        // jamais emise, et le tableau arrive vide jusqu'ici.
        $echanges = app(FlowchartExchanges::class);

        return view('organization.flowchart', [
            'organization' => $organization,
            'graph' => $builder->build($organization, $user),
            'proposals' => $echanges->proposals($organization, $user),
            'requests' => $echanges->requests($organization, $user),
            'isOrganizationMember' => $user !== null && $user->organization_id === $organization->id,
        ]);
    }
}
