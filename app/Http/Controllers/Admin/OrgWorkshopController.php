<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcquisitionJourney;
use App\Models\Organization;
use App\Models\Workshop;
use App\Models\WorkshopRegistration;
use App\Models\WorkshopSession;
use App\Models\WorkshopSessionInterest;
use App\Services\Workshops\WorkshopService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use LogicException;

/**
 * TASK-1450 — Workshop domain foundation, l'ecran OrgAdmin MINIMAL (V3 §13,
 * MASTER Q76) : liste, creation, edition, publication, retrait. Pas de
 * sessions, pas d'inscrits, pas d'analytics (B4 et suivantes). `{workshop}`
 * est resolu DANS l'Organization (404 pour un atelier d'ailleurs), jamais par
 * un binding global. Le SuperAdmin passe par les memes routes, Organization
 * explicite (OrgAdminMiddleware).
 */
class OrgWorkshopController extends Controller
{
    public function __construct(private readonly WorkshopService $workshops) {}

    public function index(Organization $organization): View
    {
        $rows = Workshop::query()->forOrganization($organization)->with(['author', 'journey'])->withCount([
            'sessions',
            'sessions as published_sessions_count' => fn ($q) => $q->where('status', WorkshopSession::STATUS_PUBLISHED),
            'registrations as registrations_count' => fn ($q) => $q->where('status', WorkshopRegistration::STATUS_REGISTERED),
            'interests as interests_count' => fn ($q) => $q->where('status', WorkshopSessionInterest::STATUS_SELECTED),
        ])->orderByRaw("case status when 'published' then 0 when 'draft' then 1 else 2 end")->orderBy('title')->get();

        return view('admin.org.workshops.index', ['organization' => $organization, 'rows' => $rows]);
    }

    public function create(Organization $organization): View
    {
        return $this->form($organization, null);
    }

    public function store(Request $request, Organization $organization): RedirectResponse
    {
        $workshop = $this->guarded(fn () => $this->workshops->create($organization, $this->validated($request), $request->user()));
        $this->applyFlyer($request, $workshop);

        return redirect()->route('organization.admin.workshops', $organization)->with('success', __('workshops.flash_created', ['title' => $workshop->title]));
    }

    public function edit(Organization $organization, string $workshop): View
    {
        return $this->form($organization, $this->resolve($organization, $workshop));
    }

    public function update(Request $request, Organization $organization, string $workshop): RedirectResponse
    {
        $target = $this->resolve($organization, $workshop);
        $this->guarded(fn () => $this->workshops->update($target, $this->validated($request), $request->user()));
        $this->applyFlyer($request, $target);

        return redirect()->route('organization.admin.workshops', $organization)->with('success', __('workshops.flash_saved', ['title' => $target->title]));
    }

    public function publish(Request $request, Organization $organization, string $workshop): RedirectResponse
    {
        $target = $this->resolve($organization, $workshop);
        abort_if($target->isPublished(), 404);
        $this->workshops->publish($target, $request->user());

        return redirect()->route('organization.admin.workshops', $organization)->with('success', __('workshops.flash_published', ['title' => $target->title]));
    }

    public function retire(Request $request, Organization $organization, string $workshop): RedirectResponse
    {
        $target = $this->resolve($organization, $workshop);
        abort_unless($target->isPublished(), 404);
        $this->workshops->retire($target, $request->user());

        return redirect()->route('organization.admin.workshops', $organization)->with('success', __('workshops.flash_retired', ['title' => $target->title]));
    }

    private function form(Organization $organization, ?Workshop $workshop): View
    {
        return view('admin.org.workshops.form', [
            'organization' => $organization,
            'workshop' => $workshop,
            'formats' => Workshop::FORMATS,
            'locales' => Workshop::supportedLocales(),
            // Les Journeys PUBLIEES de cette Organization seulement (version exacte = la ligne).
            'journeys' => AcquisitionJourney::query()->forOrganization($organization)->published()->orderBy('name')->get(),
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return Arr::except($request->validate([
            'title' => ['required', 'string', 'max:'.Workshop::MAX_TITLE_CHARS],
            'slug' => ['nullable', 'string', 'max:'.Workshop::MAX_SLUG_CHARS],
            'promise' => ['nullable', 'string', 'max:'.Workshop::MAX_PROMISE_CHARS],
            'description' => ['nullable', 'string', 'max:'.Workshop::MAX_DESCRIPTION_CHARS],
            'format' => ['required', 'string', 'in:'.implode(',', Workshop::FORMATS)],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:'.Workshop::MAX_DURATION_MINUTES],
            'locale' => ['required', 'string', 'in:'.implode(',', Workshop::supportedLocales())],
            'acquisition_journey_id' => ['nullable', 'uuid'],
            // TASK-1611 — LE visuel facultatif. Valide comme l'image d'un
            // article de blog (image reelle, formats usuels, 5 Mo), et jamais
            // transmis au service metier : il ne se range pas dans une colonne
            // de formulaire mais sur un disque, par `applyFlyer()`.
            'flyer' => ['nullable', 'image', 'mimes:'.implode(',', Workshop::FLYER_MIMES), 'max:'.Workshop::FLYER_MAX_KILOBYTES],
            'remove_flyer' => ['nullable', 'boolean'],
        ]), ['flyer', 'remove_flyer']);
    }

    /**
     * TASK-1611 — le geste flyer, apres que l'atelier existe et soit valide.
     *
     * Ordre volontaire : retirer AVANT d'attacher, pour qu'un formulaire qui
     * coche « retirer » ET depose un fichier finisse avec le NOUVEAU fichier
     * plutot qu'avec rien. Les deux gestes passent par le service, donc par la
     * garde d'acteur et par la suppression du fichier precedent.
     */
    private function applyFlyer(Request $request, Workshop $workshop): void
    {
        if ($request->boolean('remove_flyer') && $workshop->hasFlyer()) {
            $this->workshops->removeFlyer($workshop, $request->user());
        }

        if ($request->hasFile('flyer')) {
            $this->workshops->attachFlyer($workshop, $request->file('flyer'), $request->user());
        }
    }

    /**
     * Les regles metier du service (slug pris, Journey d'ailleurs, slug fige) deviennent des erreurs de formulaire.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function guarded(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (InvalidArgumentException|LogicException $exception) {
            throw ValidationException::withMessages(['slug' => [__('workshops.error_rule', ['message' => $exception->getMessage()])]]);
        }
    }

    /** Resolution DANS l'Organization : un atelier d'ailleurs n'existe pas (404). */
    private function resolve(Organization $organization, string $workshop): Workshop
    {
        return Workshop::query()->forOrganization($organization)->whereKey($workshop)->firstOrFail();
    }
}
