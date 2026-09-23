<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationGuestShellPolicy;
use App\Support\Homepage\RootDestination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * TASK-1506 — `/admin/homepage` : ce que sert la RACINE de la plateforme.
 *
 * La racine resout l'Organization PAR DEFAUT (`is_default`, repli `main`) :
 * l'ecran montre donc laquelle, parce que le choix ne veut rien dire sans
 * elle — et parce qu'une plateforme sans Organization par defaut ne peut pas
 * repondre a la question du tout, ce que l'ecran doit dire au lieu de
 * planter.
 */
class AdminRootDestinationController extends Controller
{
    public function edit(): View
    {
        $organization = $this->defaultOrganization();

        // TASK-1628 — l'ecran lit la MEME autorite que `GET /`.
        //
        // Deux notions, deliberement distinctes, que l'ancien code confondait
        // sous un seul `current` :
        //
        //  - `selected` : la case cochee. Elle suit le CHOIX STOCKE, jamais le
        //    repli. Cocher « Shell Welcome » sous pretexte qu'un gabarit hero
        //    le sert ferait enregistrer, au prochain « Enregistrer », un choix
        //    explicite que le SuperAdmin n'a jamais fait ;
        //  - `served` : le badge « Actuellement servi ». Il dit ce que la
        //    racine rend VRAIMENT, repli historique compris.
        //
        // Les deux ne coincident que sur un choix explicite — et c'est
        // exactement le defaut rapporte : sans choix, gabarit hero et Shell
        // shell-first, l'ecran annoncait « Accueil traditionnel » pendant que
        // le visiteur arrivait sur le Shell.
        $served = RootDestination::effective($organization?->root_destination, $organization?->homepage_template);

        // Lecture PURE : `forOrganization()` rend une instance non sauvegardee
        // aux defauts quand la ligne n'existe pas — consulter cet ecran ne
        // cree rien en base.
        $shellPolicy = $organization !== null ? OrganizationGuestShellPolicy::forOrganization($organization) : null;

        return view('admin.homepage.index', [
            'organization' => $organization,
            'current' => RootDestination::normalize($organization?->root_destination),
            'served' => $served,
            'stored' => $organization?->root_destination,
            'modes' => RootDestination::MODES,
            // TASK-1628 — de quoi comprendre le Shell SANS le reconfigurer ici.
            // `/admin/homepage` reste un cockpit : etat, mode, et un lien vers
            // l'ecran qui en a la responsabilite.
            'shellEnabled' => (bool) ($shellPolicy?->enabled),
            'shellDisplayMode' => $shellPolicy?->display_mode,
            'shellIsConcerned' => $served === RootDestination::SHELL_WELCOME,
            // Une Organization PRIVEE cache son blog aux anonymes
            // (`BlogController::assertOrganizationBlogIsReadable`) : servir le
            // blog a la racine leur donnerait un 404. Se dit AVANT le choix.
            'defaultOrganizationIsPrivate' => $organization !== null && ! $organization->is_public,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $organization = $this->defaultOrganization();

        if ($organization === null) {
            return redirect()->route('admin.homepage')->with('error', __('admin.root_destination_no_default_org'));
        }

        $validated = $request->validate([
            'root_destination' => ['required', 'string', Rule::in(RootDestination::MODES)],
        ]);

        $organization->update(['root_destination' => $validated['root_destination']]);

        return redirect()->route('admin.homepage')->with('success', __('admin.root_destination_saved'));
    }

    private function defaultOrganization(): ?Organization
    {
        return Organization::where('is_default', true)->first()
            ?? Organization::where('slug', 'main')->where('is_active', true)->first();
    }
}
