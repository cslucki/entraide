<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Dossier;
use App\Models\Organization;
use App\Services\Dossiers\DossierPurgeEligibility;
use App\Services\Dossiers\DossierTreePurger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * TASK-1630 — `/admin/outils/dossiers` : diagnostiquer et nettoyer les
 * arborescences de Dossiers legacy.
 *
 * TASK-1629 a ferme la creation manuelle. Restent les arborescences baties
 * AVANT cette decision : des sous-dossiers que plus rien ne produit, que le
 * produit n'a plus de raison d'entretenir, et dont l'un des effets de bord —
 * une racine de Boucle avec descendants — faisait crasher la suppression de
 * la Boucle.
 *
 * ## Trois temps, jamais deux
 *
 * Lister, previsualiser, purger. La previsualisation n'est pas une politesse :
 * une purge est IRREVERSIBLE (`forceDelete`, BLOB effaces), et l'operateur
 * doit voir le nombre de descendants et de fichiers emportes AVANT, pas
 * decouvrir apres qu'une branche de quarante fichiers est partie.
 * Aucune mutation par GET.
 *
 * ## Le droit vient du middleware, la protection du serveur
 *
 * Le groupe `/admin` porte deja `['auth', 'admin']` : SuperAdmin et personne
 * d'autre — un OrgAdmin (`organizations.admin_id`) n'est pas `is_admin` et
 * n'entre pas. L'ecran n'est donc pas le garde-fou ; il ne fait que montrer.
 * La protection des racines actives est re-verifiee a la purge par
 * `DossierPurgeEligibility`, la meme primitive qui a dessine les badges.
 *
 * ## Le filtre Organization est une LECTURE
 *
 * Meme doctrine qu'`AdminDrivesController` : `?organization=<slug>` restreint
 * l'affichage, il n'accorde rien, et un slug inconnu est dit — sans quoi on
 * croirait voir une Organization vide.
 */
class AdminDossierCleanupController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request, DossierPurgeEligibility $eligibility): View
    {
        $slug = trim((string) $request->query('organization', ''));
        $only = $slug !== '' ? Organization::query()->where('slug', $slug)->first() : null;

        $filters = [
            'organization' => $slug,
            'type' => (string) $request->query('type', ''),
            'state' => (string) $request->query('state', ''),
            'search' => trim((string) $request->query('search', '')),
        ];

        $dossiers = $this->listing($only, $filters);

        return view('admin.outils.dossiers', [
            'dossiers' => $dossiers,
            'organizations' => Organization::query()->orderBy('name')->get(['id', 'slug', 'name']),
            'selected' => $only,
            'unknownOrganization' => $slug !== '' && $only === null ? $slug : null,
            'filters' => $filters,
            'eligibility' => $eligibility,
        ]);
    }

    /**
     * Le deuxieme temps : ce que la selection emporterait.
     *
     * POST, jamais GET — c'est une etape de confirmation, mais elle porte une
     * selection et ne doit pas etre rejouable depuis un historique.
     */
    public function preview(Request $request, DossierTreePurger $purger, DossierPurgeEligibility $eligibility): View|RedirectResponse
    {
        $selection = $this->selection($request);

        if ($selection->isEmpty()) {
            return back()->with('error', __('admin.dossiers_cleanup.none_selected'));
        }

        [$autorises, $refuses] = $this->partition($selection, $eligibility);

        if ($autorises->isEmpty()) {
            return back()->with('error', __('admin.dossiers_cleanup.all_protected'));
        }

        return view('admin.outils.dossiers-preview', [
            'preview' => $purger->preview($autorises),
            'allowed' => $autorises,
            'refused' => $refuses,
            'organizations' => Organization::query()
                ->whereIn('id', $autorises->pluck('organization_id')->unique())
                ->orderBy('name')->get(['id', 'slug', 'name']),
            'eligibility' => $eligibility,
            'filters' => $request->only(['organization', 'type', 'state', 'search']),
        ]);
    }

    /**
     * Le troisieme temps, et le seul qui ecrit.
     *
     * Le jeton de confirmation n'est pas decoratif : il garantit que cette
     * requete vient de l'ecran de preview et pas d'un POST direct qui aurait
     * saute l'etape ou l'operateur a vu les nombres.
     */
    public function purge(Request $request, DossierTreePurger $purger, DossierPurgeEligibility $eligibility): RedirectResponse
    {
        $request->validate(['confirm' => ['required', 'accepted']]);

        $selection = $this->selection($request);

        if ($selection->isEmpty()) {
            return redirect()->route('admin.outils.dossiers')
                ->with('error', __('admin.dossiers_cleanup.none_selected'));
        }

        // La garde SERVEUR. L'ecran a deja ecarte les racines protegees ;
        // cette ligne est ce qui rend l'ecran facultatif.
        [$autorises, $refuses] = $this->partition($selection, $eligibility);

        if ($autorises->isEmpty()) {
            return redirect()->route('admin.outils.dossiers')
                ->with('error', __('admin.dossiers_cleanup.all_protected'));
        }

        $compte = $purger->purge($autorises);

        $message = __('admin.dossiers_cleanup.purged', [
            'dossiers' => $compte['dossiers'],
            'files' => $compte['files'],
        ]);

        if ($refuses->isNotEmpty()) {
            $message .= ' '.__('admin.dossiers_cleanup.skipped_protected', ['count' => $refuses->count()]);
        }

        return redirect()->route('admin.outils.dossiers', array_filter($request->only(['organization', 'type', 'state', 'search'])))
            ->with('success', $message);
    }

    /**
     * @return LengthAwarePaginator<int, Dossier>
     */
    private function listing(?Organization $only, array $filters)
    {
        $query = Dossier::withTrashed()
            ->with([
                'organization:id,slug,name',
                'owner:id,first_name,name,email,banned_at',
                'loop:id,name,status',
                'parent:id,name',
            ])
            ->withCount(['children', 'files', 'dossierBlogPosts']);

        if ($only !== null) {
            $query->where('organization_id', $only->getKey());
        }

        match ($filters['type']) {
            DossierPurgeEligibility::TYPE_LEGACY_CHILD => $query->whereNotNull('parent_id'),
            DossierPurgeEligibility::TYPE_LOOP_ROOT => $query->whereNull('parent_id')->whereNotNull('loop_id'),
            DossierPurgeEligibility::TYPE_USER_ROOT => $query->whereNull('parent_id')->whereNotNull('owner_id'),
            'root' => $query->whereNull('parent_id'),
            default => null,
        };

        match ($filters['state']) {
            'deleted' => $query->whereNotNull('deleted_at'),
            'active' => $query->whereNull('deleted_at'),
            default => null,
        };

        if ($filters['search'] !== '') {
            $query->where('name', 'like', '%'.$filters['search'].'%');
        }

        return $query
            // Les sous-dossiers d'abord : ce sont eux que l'outil sert a
            // trouver, et un tri alphabetique global les noierait parmi les
            // racines legitimes.
            ->orderByRaw('CASE WHEN parent_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('name')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * @return Collection<int, Dossier>
     */
    private function selection(Request $request): Collection
    {
        $ids = collect($request->input('dossiers', []))
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return Dossier::withTrashed()
            ->with(['loop:id,name,status', 'owner:id,first_name,name,banned_at', 'organization:id,slug,name'])
            ->whereIn('id', $ids)
            ->get();
    }

    /**
     * @param  Collection<int, Dossier>  $selection
     * @return array{0: Collection<int, Dossier>, 1: Collection<int, Dossier>}
     */
    private function partition(Collection $selection, DossierPurgeEligibility $eligibility): array
    {
        return [
            $selection->filter(fn (Dossier $d) => $eligibility->isPurgeable($d))->values(),
            $selection->reject(fn (Dossier $d) => $eligibility->isPurgeable($d))->values(),
        ];
    }
}
