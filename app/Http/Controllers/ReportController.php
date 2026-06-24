<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function storeService(Request $request, Service $service): RedirectResponse
    {
        $organization = currentOrganization();
        if (! $organization || $service->organization_id !== $organization->id) {
            abort(404);
        }

        $data = $request->validate([
            'reason' => 'required|string|max:255',
            'details' => 'nullable|string|max:1000',
        ]);

        // Empêcher l'auteur de se signaler lui-même
        if (auth()->id() === $service->user_id) {
            return back()->with('error', 'Vous ne pouvez pas signaler votre propre service.');
        }

        Report::firstOrCreate(
            ['reporter_id' => auth()->id(), 'reportable_type' => Service::class, 'reportable_id' => $service->id],
            array_merge($data, ['reporter_id' => auth()->id(), 'reportable_type' => Service::class, 'reportable_id' => $service->id, 'organization_id' => $organization->id])
        );

        return back()->with('success', 'Signalement envoyé. Merci !');
    }

    public function orgStoreService(Request $request, string $org, Service $service): RedirectResponse
    {
        return $this->storeService($request, $service);
    }

    public function storeRequest(Request $httpRequest, ServiceRequest $serviceRequest): RedirectResponse
    {
        $organization = currentOrganization();
        if (! $organization || $serviceRequest->organization_id !== $organization->id) {
            abort(404);
        }

        $data = $httpRequest->validate([
            'reason' => 'required|string|max:255',
            'details' => 'nullable|string|max:1000',
        ]);

        if (auth()->id() === $serviceRequest->user_id) {
            return back()->with('error', 'Vous ne pouvez pas signaler votre propre demande.');
        }

        Report::firstOrCreate(
            ['reporter_id' => auth()->id(), 'reportable_type' => ServiceRequest::class, 'reportable_id' => $serviceRequest->id],
            array_merge($data, ['reporter_id' => auth()->id(), 'reportable_type' => ServiceRequest::class, 'reportable_id' => $serviceRequest->id, 'organization_id' => $organization->id])
        );

        return back()->with('success', 'Signalement envoyé. Merci !');
    }

    public function orgStoreRequest(Request $httpRequest, string $org, ServiceRequest $serviceRequest): RedirectResponse
    {
        return $this->storeRequest($httpRequest, $serviceRequest);
    }

    public function storeUser(Request $request, User $user): RedirectResponse
    {
        $organization = currentOrganization();
        if (! $organization || $user->organization_id !== $organization->id) {
            abort(404);
        }

        $data = $request->validate([
            'reason' => 'required|string|max:255',
            'details' => 'nullable|string|max:1000',
        ]);

        if (auth()->id() === $user->id) {
            return back()->with('error', 'Vous ne pouvez pas vous signaler vous-même.');
        }

        Report::firstOrCreate(
            ['reporter_id' => auth()->id(), 'reportable_type' => User::class, 'reportable_id' => $user->id],
            array_merge($data, ['reporter_id' => auth()->id(), 'reportable_type' => User::class, 'reportable_id' => $user->id, 'organization_id' => $organization->id])
        );

        return back()->with('success', 'Signalement envoyé. Merci !');
    }

    public function orgStoreUser(Request $request, string $org, User $user): RedirectResponse
    {
        return $this->storeUser($request, $user);
    }
}
