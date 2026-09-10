<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
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

        return view('admin.homepage.index', [
            'organization' => $organization,
            'current' => RootDestination::normalize($organization?->root_destination),
            'stored' => $organization?->root_destination,
            'modes' => RootDestination::MODES,
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
