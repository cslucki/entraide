<?php

namespace App\Http\Controllers;

use App\Models\GuestMessage;
use App\Models\Organization;
use App\Services\GuestShell\GuestShellSurface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * TASK-1442 — SW-8a : le controleur PUBLIC du Shell Welcome, mince par
 * construction. Distinct du Shell membre (Livewire `ai-shell`, `@auth`
 * intact) : ici, jamais un utilisateur, jamais `ai_interactions`.
 *
 *  - GET  /org/{organization}/shell          : lecture pure (reprise sans creation)
 *  - POST /org/{organization}/shell/message  : le premier geste reel
 */
class GuestShellController extends Controller
{
    public function show(Request $request, string $organization, GuestShellSurface $surface): JsonResponse
    {
        return response()->json($surface->read($this->publicOrganization($organization), $request));
    }

    public function message(Request $request, string $organization, GuestShellSurface $surface): JsonResponse
    {
        $target = $this->publicOrganization($organization);
        $data = $request->validate([
            'message' => ['required', 'string', 'max:'.GuestMessage::maxUserBodyLength()],
        ]);

        return response()->json($surface->turn($target, $request, $data['message']));
    }

    /** Public != Global : une Organization inactive ou non publique n'a aucune surface Guest (404, comme sa landing). */
    private function publicOrganization(string $slug): Organization
    {
        $organization = Organization::findBySlug($slug);
        abort_if($organization === null || ! $organization->is_active || ! $organization->is_public, 404);

        return $organization;
    }
}
