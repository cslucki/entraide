<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DossierFile;
use App\Models\Organization;
use App\Services\Dossiers\DossierFileIndexingDispatcher;
use App\Services\Dossiers\DossierFileRemover;
use App\Services\Dossiers\OrganizationFileInventory;
use App\Services\Dossiers\OrganizationRagOverview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
    /**
     * Combien d'extraits le tiroir rend, au plus. Un document de plusieurs
     * milliers d'extraits ne doit ni saturer la memoire ni produire une page
     * illisible : on montre le debut, et on DIT combien il y en a en tout.
     */
    private const CHUNK_PREVIEW = 20;

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

    /**
     * TASK-1515 — « Voir extraits » : ce que l'IA a REELLEMENT retenu d'un
     * fichier. Lecture seule, zero appel provider, zero vecteur.
     *
     * La primitive est celle de TASK-1307, `OrganizationRagOverview::chunksFor()`,
     * exactement celle qu'utilise la console d'Organization : la plateforme
     * et le tenant doivent dire la meme chose du meme document. Seule la
     * BORNE differe — ici on ne connait pas la taille des documents des
     * autres.
     *
     * Le fragment sort en `no-store` : c'est du contenu de document d'un
     * tenant, rendu dans une console partagee. Il n'a rien a faire dans un
     * cache, ni chez un intermediaire, ni dans l'historique du navigateur.
     */
    public function chunks(Organization $organization, DossierFile $file, OrganizationRagOverview $overview): Response
    {
        abort_unless((string) $file->organization_id === (string) $organization->getKey(), 404);

        $source = $overview->chunksFor(
            (string) $organization->getKey(),
            'file',
            (string) $file->getKey(),
            self::CHUNK_PREVIEW,
        );

        abort_if($source === null, 404);

        // Le partiel est celui de la console d'Organization, tel quel : il ne
        // depend d'aucun contexte org-admin et n'ecrit aucun `var(--bp-*)`,
        // que `layouts/admin` n'emet pas.
        $html = view('admin.org.partials.ai-knowledge-source', ['source' => $source])->render();

        return response($html)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store, no-cache, private, max-age=0');
    }

    /**
     * TASK-1515 — suppression. Le geste n'est pas invente ici : il vit dans
     * `DossierFileRemover`, extrait de `DossierFileController::destroy()`,
     * et c'est le meme pour le membre et pour la plateforme.
     *
     * Ce qui est propre a cette porte, c'est le DROIT et le PERIMETRE :
     *
     *  - le droit vient du middleware `admin` (la `DossierPolicy` parle de
     *    proprietaire de Dossier ou d'admin de Boucle, ce qu'un superadmin
     *    n'est ni l'un ni l'autre) ;
     *  - le perimetre est re-verifie ici et pas ailleurs : `DossierFile` ne
     *    porte AUCUN scope global de tenant, le route-model-binding accepterait
     *    l'UUID d'un fichier d'une autre Organization. Une URL forgee doit
     *    rendre 404, jamais supprimer.
     *
     * Le verbe est DELETE : aucune suppression n'est atteignable en GET.
     */
    public function destroy(Organization $organization, DossierFile $file, DossierFileRemover $remover): RedirectResponse
    {
        abort_unless((string) $file->organization_id === (string) $organization->getKey(), 404);

        // Lu AVANT la suppression : apres, l'operateur n'a plus aucun moyen
        // de savoir ce qu'il vient d'effacer.
        $name = (string) ($file->display_name ?: $file->original_name);

        $remover->remove($file);

        return back()->with('success', __('drives.delete_done', ['name' => $name]));
    }
}
