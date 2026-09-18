<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcquisitionJourney;
use App\Models\Organization;
use App\Models\UsageReference;
use App\Services\Acquisition\AcquisitionJourneyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * TASK-1446 — AcquisitionJourney foundation, l'ecran OrgAdmin MINIMAL
 * (MASTER Q74) : liste, brouillon, edition de brouillon, publication, retrait.
 * Pas de dashboard, pas d'analytics. `{journey}` est resolu DANS
 * l'Organization (404 pour une Journey d'ailleurs), jamais par un binding
 * global — meme doctrine tenant que le Mini-CRM. Le SuperAdmin passe par les
 * memes routes, Organization explicite (OrgAdminMiddleware).
 */
class OrgAcquisitionController extends Controller
{
    public function __construct(private readonly AcquisitionJourneyService $journeys) {}

    public function index(Organization $organization): View
    {
        $rows = AcquisitionJourney::query()->forOrganization($organization)->with(['author', 'publisher'])->orderBy('key')->orderByDesc('version')->get();

        return view('admin.org.acquisition.index', [
            'organization' => $organization,
            'grouped' => $rows->groupBy('key'),
            'goals' => AcquisitionJourney::GOALS,
        ]);
    }

    public function create(Organization $organization): View
    {
        return $this->form($organization, null);
    }

    public function store(Request $request, Organization $organization): RedirectResponse
    {
        $data = $this->validated($request, withKey: true);
        $journey = $this->journeys->createDraft($organization, $data, $request->user());

        return redirect()->route('organization.admin.acquisition', $organization)->with('success', __('acquisition.flash_draft_created', ['name' => $journey->name, 'version' => $journey->version]));
    }

    public function edit(Organization $organization, string $journey): View
    {
        $target = $this->resolveDraft($organization, $journey);

        return $this->form($organization, $target);
    }

    public function update(Request $request, Organization $organization, string $journey): RedirectResponse
    {
        $target = $this->resolveDraft($organization, $journey);
        $this->journeys->updateDraft($target, $this->validated($request, withKey: false), $request->user());

        return redirect()->route('organization.admin.acquisition', $organization)->with('success', __('acquisition.flash_draft_saved', ['name' => $target->name, 'version' => $target->version]));
    }

    public function publish(Request $request, Organization $organization, string $journey): RedirectResponse
    {
        $target = $this->resolveDraft($organization, $journey);
        $this->journeys->publish($target, $request->user());

        return redirect()->route('organization.admin.acquisition', $organization)->with('success', __('acquisition.flash_published', ['name' => $target->name, 'version' => $target->version]));
    }

    public function retire(Request $request, Organization $organization, string $journey): RedirectResponse
    {
        $target = $this->resolve($organization, $journey);
        abort_unless($target->isPublished(), 404);
        $this->journeys->retire($target, $request->user());

        return redirect()->route('organization.admin.acquisition', $organization)->with('success', __('acquisition.flash_retired', ['name' => $target->name, 'version' => $target->version]));
    }

    private function form(Organization $organization, ?AcquisitionJourney $journey): View
    {
        return view('admin.org.acquisition.form', [
            'organization' => $organization,
            'journey' => $journey,
            'goals' => AcquisitionJourney::GOALS,
            'locales' => AcquisitionJourney::supportedLocales(),
            // TASK-1477 : un parcours d'acquisition s'adresse a un VISITEUR.
            // Les surfaces MEMBRE n'ont rien a y faire.
            'surfaces' => UsageReference::SURFACES_PUBLIC,
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $withKey): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:'.AcquisitionJourney::MAX_NAME_CHARS],
            'locale' => ['required', 'string', 'in:'.implode(',', AcquisitionJourney::supportedLocales())],
            'conversion_goal' => ['required', 'string', 'in:'.implode(',', AcquisitionJourney::GOALS)],
            'campaign' => ['nullable', 'string', 'max:'.AcquisitionJourney::MAX_CAMPAIGN_CHARS],
            'usage_reference_surface_key' => ['nullable', 'string', 'in:'.implode(',', UsageReference::SURFACES_PUBLIC)],
        ];
        if ($withKey) {
            // La cle est normalisee en slug par le service (« Ateliers Septembre » -> ateliers-septembre) ; vide = derivee du nom.
            $rules['key'] = ['nullable', 'string', 'max:'.AcquisitionJourney::MAX_KEY_CHARS];
        }

        return $request->validate($rules);
    }

    /** Resolution DANS l'Organization : une Journey d'ailleurs n'existe pas (404). */
    private function resolve(Organization $organization, string $journey): AcquisitionJourney
    {
        return AcquisitionJourney::query()->forOrganization($organization)->whereKey($journey)->firstOrFail();
    }

    private function resolveDraft(Organization $organization, string $journey): AcquisitionJourney
    {
        $target = $this->resolve($organization, $journey);
        abort_unless($target->isDraft(), 404);

        return $target;
    }
}
