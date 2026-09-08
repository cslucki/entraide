<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Workshop;
use App\Models\WorkshopSession;
use App\Services\Workshops\WorkshopRegistrationService;
use App\Services\Workshops\WorkshopSessionFull;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use LogicException;

/**
 * TASK-1453 — « Je confirme ma participation » depuis la page publique d'un
 * atelier (Growth V3 §10/§11). Geste MEMBRE : User connecte, email verifie,
 * meme Organization ; sinon 403/redirection vers la verification. La session
 * est publiee, a venir, de cet atelier publie — sinon 404. Complet au moment
 * canonique = refus honnete, rien d'ecrit.
 */
class WorkshopRegistrationController extends Controller
{
    public function __construct(private readonly WorkshopRegistrationService $registrations) {}

    public function register(Request $request, string $organization, string $workshop, string $session): RedirectResponse
    {
        [$target, $page, $row] = $this->resolve($organization, $workshop, $session);
        $user = $request->user();
        abort_if($user === null || (string) $user->organization_id !== (string) $target->getKey(), 403);
        if ($user->email_verified_at === null) {
            return redirect()->route('verification.notice');
        }

        try {
            $this->registrations->register($row, $user);
        } catch (WorkshopSessionFull) {
            return redirect()->route('organization.workshop.show', ['organization' => $target->slug, 'workshop' => $page->slug])->with('workshop_registration_full', $row->getKey());
        } catch (LogicException) {
            abort(404);
        }

        return redirect()->route('organization.workshop.show', ['organization' => $target->slug, 'workshop' => $page->slug])->with('workshop_registration', $row->getKey());
    }

    public function cancel(Request $request, string $organization, string $workshop, string $session): RedirectResponse
    {
        [$target, $page, $row] = $this->resolve($organization, $workshop, $session);
        $user = $request->user();
        abort_if($user === null || (string) $user->organization_id !== (string) $target->getKey(), 403);

        $this->registrations->cancel($row, $user);

        return redirect()->route('organization.workshop.show', ['organization' => $target->slug, 'workshop' => $page->slug]);
    }

    /** @return array{0: Organization, 1: Workshop, 2: WorkshopSession} */
    private function resolve(string $organization, string $workshop, string $session): array
    {
        $target = Organization::findBySlug($organization);
        abort_if($target === null || ! $target->is_active || ! $target->is_public, 404);

        $page = Workshop::query()->forOrganization($target)->published()->where('slug', $workshop)->first();
        abort_if($page === null, 404);

        $row = $page->publicUpcomingSessions()->whereKey($session)->first();
        abort_if($row === null, 404);

        return [$target, $page, $row];
    }
}
