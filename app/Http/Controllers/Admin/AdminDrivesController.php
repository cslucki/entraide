<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DossierFile;
use App\Models\Organization;
use App\Services\Dossiers\DossierFileIndexingDispatcher;
use App\Services\Dossiers\OrganizationFileInventory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * TASK-1514 — `/admin/drives` : les fichiers de TOUTES les Organizations.
 *
 * Cyril : « je suis alle sur le superadmin, aucune page qui contient la liste
 * des fichiers, et ceci avec une selection selon l'organisation ».
 *
 * ## Le meme read model, deux points d'entree nommes
 *
 * `OrganizationFileInventory::forPlatform()` partage toute sa construction avec
 * `forOrganization()` (TASK-1513) : meme jointure agregee, memes etats, memes
 * tris. Deux implementations divergeraient au premier changement de colonne, et
 * la console d'Organization et celle de la plateforme ne diraient plus la meme
 * chose du meme fichier.
 *
 * ## Le filtre est une LECTURE, pas un droit
 *
 * `?organization=<slug>` restreint l'affichage ; il n'accorde rien. Le droit
 * vient du middleware `admin`, et de lui seul — meme doctrine que
 * `AdminAiQualityController`. Un slug inconnu ne filtre donc pas en silence :
 * l'ecran le dit, sinon on croirait voir une Organization vide.
 *
 * ## L'ecriture exige l'Organization DANS l'URL
 *
 * `DossierFile` ne porte AUCUN scope global de tenant : le route-model-binding
 * accepterait volontiers l'UUID d'un fichier d'une autre Organization. La
 * reindexation compare donc explicitement, comme le fait deja `AdminCrmController`.
 */
class AdminDrivesController extends Controller
{
    public function index(Request $request, OrganizationFileInventory $inventory): View
    {
        $slug = trim((string) $request->query('organization', ''));
        $only = $slug !== '' ? Organization::query()->where('slug', $slug)->first() : null;

        $filters = [
            'search' => (string) $request->query('search', ''),
            'state' => (string) $request->query('state', ''),
            'sort' => (string) $request->query('sort', 'created_at'),
            'direction' => (string) $request->query('direction', 'desc'),
        ];

        return view('admin.drives', [
            'files' => $inventory->forPlatform($only, $filters),
            'organizations' => Organization::query()->orderBy('name')->get(['id', 'slug', 'name']),
            'selected' => $only,
            // Un slug demande mais introuvable : on ne laisse pas croire a une
            // Organization vide.
            'unknownOrganization' => $slug !== '' && $only === null ? $slug : null,
            'filters' => $filters,
            'states' => OrganizationFileInventory::STATES,
        ]);
    }

    public function reindex(Organization $organization, DossierFile $file, DossierFileIndexingDispatcher $dispatcher, OrganizationFileInventory $inventory): RedirectResponse
    {
        abort_unless((string) $file->organization_id === (string) $organization->getKey(), 404);
        abort_if($file->dossier_id === null, 404);
        abort_if(
            $inventory->stateOf((string) $file->mime_type, (string) $file->original_name, 0) === OrganizationFileInventory::STATE_NOT_INGESTIBLE,
            422,
        );

        $dispatcher->dispatchForFile($file);

        // « Mis en file », jamais « reindexe » : la queue est asynchrone.
        return back()->with('success', __('drives.reindex_queued', ['name' => $file->display_name ?: $file->original_name]));
    }
}
