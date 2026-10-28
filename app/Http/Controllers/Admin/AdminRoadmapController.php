<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Loop;
use App\Models\LoopRoadmapItem;
use App\Models\Organization;
use App\Models\Scopes\BelongsToOrganizationScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * TASK-1718 volet 2 — la Roadmap de TOUTES les Organizations, vue du SuperAdmin.
 *
 * ## Ce que cet ecran n'est PAS
 *
 * Un second systeme de taches. MASTER : « Reutiliser les modeles, controleurs
 * et composants Roadmap existants ». Il n'y a donc ici **aucun modele
 * nouveau**, aucune table, aucune migration : `LoopRoadmapItem` est lu tel
 * quel, avec ses statuts, son auteur et sa Boucle.
 *
 * ## Pourquoi un controleur a part, alors
 *
 * `LoopRoadmapCard` est un composant Livewire **borne a UNE Boucle** : il
 * prend `$loop` en parametre et toutes ses gardes (`canWrite`, `canModify`,
 * `isPrivileged`) repondent « dans cette Boucle ». Lui demander de rendre les
 * taches de toutes les Organizations reviendrait a percer ces gardes-la —
 * exactement ce qu'il ne faut pas faire. La surface PLATEFORME est donc
 * separee, et c'est le middleware `is_admin` qui la garde, comme les vingt
 * autres ecrans de `/admin`.
 *
 * ## Le scope de tenant est leve EXPLICITEMENT, et nulle part ailleurs
 *
 * `LoopRoadmapItem` porte `BelongsToOrganizationScope` : sans le retirer, cet
 * ecran ne montrerait que l'Organization courante du SuperAdmin — c'est-a-dire
 * presque rien, et en silence. Le retrait est donc ecrit ici, nomme, sur les
 * trois requetes de cette classe et sur aucune autre.
 *
 * Ce n'est pas une brèche : `/admin` est une surface plateforme, gardee en
 * amont par `is_admin`. La lecon de TASK-1678 reste vraie — un test
 * d'isolation de tenant pose sur `/admin` ne mesure rien — et c'est pourquoi
 * les gardes de cette TASK portent sur le REFUS d'entrer, pas sur ce qu'on
 * voit une fois entre.
 */
class AdminRoadmapController extends Controller
{
    public function index(Request $request): View
    {
        $organizationId = $request->string('organization_id')->toString();
        $loopId = $request->string('loop_id')->toString();

        $query = LoopRoadmapItem::withoutGlobalScope(BelongsToOrganizationScope::class)
            ->with(['loop.organization', 'creator'])
            ->withCount('messages');

        if ($organizationId !== '') {
            $query->where('organization_id', $organizationId);
        }

        // Le filtre par Boucle est INDEPENDANT de celui par Organization : on
        // peut arriver ici par un lien qui ne porte que la Boucle, et la
        // forcer a choisir d'abord son Organization serait un clic pour rien.
        if ($loopId !== '') {
            $query->where('loop_id', $loopId);
        }

        $items = $query->orderByDesc('created_at')->paginate(50)->withQueryString();

        return view('admin.roadmap.index', [
            'items' => $items,
            'organizations' => Organization::orderBy('name')->get(['id', 'name', 'slug']),
            // Les Boucles du filtre suivent l'Organization choisie : proposer
            // les 200 Boucles de la plateforme quand on en regarde une seule
            // rendrait la liste inutilisable.
            'loops' => Loop::withoutGlobalScope(BelongsToOrganizationScope::class)
                ->when($organizationId !== '', fn ($q) => $q->where('organization_id', $organizationId))
                ->orderBy('name')
                ->get(['id', 'name', 'organization_id']),
            'organizationId' => $organizationId,
            'loopId' => $loopId,
            'statuses' => LoopRoadmapItem::STATUSES,
        ]);
    }

    public function update(Request $request, string $item): RedirectResponse
    {
        $tache = $this->resoudre($item);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'status' => 'required|in:'.implode(',', LoopRoadmapItem::STATUSES),
        ]);

        $tache->update($data);

        return back()->with('success', __('admin.roadmap_updated'));
    }

    public function destroy(string $item): RedirectResponse
    {
        $tache = $this->resoudre($item);

        // `forceDelete()` et non `delete()` : `LoopRoadmapItem` porte
        // `SoftDeletes`, donc `delete()` ARCHIVE. L'archivage existe deja dans
        // la Boucle, ou il appartient a ses membres — le refaire ici
        // n'apporterait rien et l'ecran mentirait en disant « supprimee ».
        //
        // Ce que le SuperAdmin fait ici est le geste que personne d'autre ne
        // peut faire, et c'est pourquoi la confirmation du formulaire dit
        // « definitivement ».
        $tache->forceDelete();

        return back()->with('success', __('admin.roadmap_deleted'));
    }

    /**
     * La liaison se fait A LA MAIN, et c'est delibere.
     *
     * Une liaison implicite `LoopRoadmapItem $item` passerait par le scope de
     * tenant et rendrait 404 sur toute tache d'une autre Organization —
     * c'est-a-dire sur presque toutes, et sans rien dire. Le retrait du scope
     * doit donc etre ecrit la ou la tache est trouvee.
     */
    private function resoudre(string $id): LoopRoadmapItem
    {
        // `withTrashed()` : une tache deja archivee dans sa Boucle reste une
        // tache que le SuperAdmin doit pouvoir supprimer pour de bon.
        return LoopRoadmapItem::withoutGlobalScope(BelongsToOrganizationScope::class)
            ->withTrashed()
            ->findOrFail($id);
    }
}
