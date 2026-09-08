<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Workshop;
use App\Models\WorkshopSession;
use App\Services\Workshops\WorkshopSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use LogicException;

/**
 * TASK-1451 — B4-A : les sessions d'un atelier, ecran OrgAdmin MINIMAL (V3 §13,
 * MASTER Q78) : liste, brouillon, edition, publication, annulation. Ni
 * inscrits, ni interets, ni meeting_url. `{workshop}` est resolu DANS
 * l'Organization et `{session}` DANS le Workshop (404 ailleurs), jamais par un
 * binding global.
 */
class OrgWorkshopSessionController extends Controller
{
    public function __construct(private readonly WorkshopSessionService $sessions) {}

    public function index(Organization $organization, string $workshop): View
    {
        $target = $this->workshop($organization, $workshop);
        $rows = $target->sessions()->with('author')->orderBy('starts_at')->get();

        return view('admin.org.workshops.sessions.index', ['organization' => $organization, 'workshop' => $target, 'rows' => $rows]);
    }

    public function create(Organization $organization, string $workshop): View
    {
        return $this->form($organization, $this->workshop($organization, $workshop), null);
    }

    public function store(Request $request, Organization $organization, string $workshop): RedirectResponse
    {
        $target = $this->workshop($organization, $workshop);
        $this->guarded(fn () => $this->sessions->create($target, $this->validated($request), $request->user()));

        return redirect()->route('organization.admin.workshops.sessions', [$organization, $target])->with('success', __('workshops.flash_session_created'));
    }

    public function edit(Organization $organization, string $workshop, string $session): View
    {
        $target = $this->workshop($organization, $workshop);

        return $this->form($organization, $target, $this->session($target, $session));
    }

    public function update(Request $request, Organization $organization, string $workshop, string $session): RedirectResponse
    {
        $target = $this->workshop($organization, $workshop);
        $row = $this->session($target, $session);
        $this->guarded(fn () => $this->sessions->update($row, $this->validated($request), $request->user()));

        return redirect()->route('organization.admin.workshops.sessions', [$organization, $target])->with('success', __('workshops.flash_session_saved'));
    }

    public function publish(Request $request, Organization $organization, string $workshop, string $session): RedirectResponse
    {
        $target = $this->workshop($organization, $workshop);
        $row = $this->session($target, $session);
        abort_unless($row->isDraft(), 404);
        $this->sessions->publish($row, $request->user());

        return redirect()->route('organization.admin.workshops.sessions', [$organization, $target])->with('success', __('workshops.flash_session_published'));
    }

    public function cancel(Request $request, Organization $organization, string $workshop, string $session): RedirectResponse
    {
        $target = $this->workshop($organization, $workshop);
        $row = $this->session($target, $session);
        abort_if($row->isCancelled(), 404);
        $this->sessions->cancel($row, $request->user());

        return redirect()->route('organization.admin.workshops.sessions', [$organization, $target])->with('success', __('workshops.flash_session_cancelled'));
    }

    private function form(Organization $organization, Workshop $workshop, ?WorkshopSession $session): View
    {
        $timezone = $session?->timezone ?? old('timezone') ?? WorkshopSession::FALLBACK_TIMEZONE;

        return view('admin.org.workshops.sessions.form', [
            'organization' => $organization,
            'workshop' => $workshop,
            'session' => $session,
            'timezones' => WorkshopSession::timezoneOptions($timezone),
            'defaultTimezone' => $timezone,
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'starts_at' => ['required', 'string', 'max:32'],
            'ends_at' => ['nullable', 'string', 'max:32'],
            'timezone' => ['required', 'string', 'max:64'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:'.WorkshopSession::MAX_CAPACITY],
            'location' => ['nullable', 'string', 'max:'.WorkshopSession::MAX_LOCATION_CHARS],
        ]);
    }

    /**
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
            throw ValidationException::withMessages(['starts_at' => [__('workshops.error_rule', ['message' => $exception->getMessage()])]]);
        }
    }

    /** Resolution DANS l'Organization : un atelier d'ailleurs n'existe pas (404). */
    private function workshop(Organization $organization, string $workshop): Workshop
    {
        return Workshop::query()->forOrganization($organization)->whereKey($workshop)->firstOrFail();
    }

    /** Resolution DANS le Workshop : une session d'ailleurs n'existe pas (404). */
    private function session(Workshop $workshop, string $session): WorkshopSession
    {
        return $workshop->sessions()->whereKey($session)->firstOrFail();
    }
}
