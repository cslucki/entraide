<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrgAdminController extends Controller
{
    public function dashboard(Organization $organization): View
    {
        $orgId = $organization->id;
        $stats = [
            'users' => User::where('organization_id', $orgId)->count(),
            'loops' => Loop::where('organization_id', $orgId)->count(),
            'services' => Service::where('organization_id', $orgId)->where('status', 'active')->count(),
            'requests' => ServiceRequest::where('organization_id', $orgId)->count(),
        ];
        $recentUsers = User::where('organization_id', $orgId)->latest()->limit(5)->get();

        return view('admin.org.dashboard', [
            'organization' => $organization,
            'stats' => $stats,
            'recentUsers' => $recentUsers,
        ]);
    }

    public function services(Request $request, Organization $organization): View
    {
        $orgId = $organization->id;
        $query = Service::where('organization_id', $orgId)->with(['user', 'category']);

        if ($request->filled('search')) {
            $query->where('title', 'like', '%'.$request->search.'%');
        }

        if ($request->filled('status')) {
            match ($request->status) {
                'active' => $query->where('status', 'active')->whereNull('deleted_at'),
                'paused' => $query->where('status', 'paused')->whereNull('deleted_at'),
                'deleted' => $query->onlyTrashed(),
                default => null,
            };
        }

        $services = $query->latest()->paginate(25)->withQueryString();

        return view('admin.org.services', [
            'organization' => $organization,
            'services' => $services,
        ]);
    }

    public function requests(Request $request, Organization $organization): View
    {
        $orgId = $organization->id;
        $query = ServiceRequest::where('organization_id', $orgId)->with(['user', 'category']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where('title', 'like', '%'.$request->search.'%');
        }

        $requests = $query->latest()->paginate(25)->withQueryString();

        return view('admin.org.requests', [
            'organization' => $organization,
            'requests' => $requests,
        ]);
    }

    public function transactions(Request $request, Organization $organization): View
    {
        $orgId = $organization->id;
        $query = Transaction::where('organization_id', $orgId)->with(['buyer', 'seller', 'service']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->whereHas('buyer', fn ($u) => $u->where('name', 'like', '%'.$request->search.'%'))
                    ->orWhereHas('seller', fn ($u) => $u->where('name', 'like', '%'.$request->search.'%'));
            });
        }

        $transactions = $query->latest()->paginate(25)->withQueryString();

        return view('admin.org.transactions', [
            'organization' => $organization,
            'transactions' => $transactions,
        ]);
    }

    public function closeRequest(Organization $organization, string $serviceRequest): RedirectResponse
    {
        $serviceRequest = ServiceRequest::where('organization_id', $organization->id)->findOrFail($serviceRequest);
        $serviceRequest->update(['status' => 'closed']);

        return back()->with('success', 'Demande clôturée.');
    }

    public function loops(Organization $organization): View
    {
        return $this->comingSoon($organization, __('navigation.org_admin_loops'));
    }

    public function messages(Organization $organization): View
    {
        return $this->comingSoon($organization, __('navigation.org_admin_messages'));
    }

    public function blog(Organization $organization): View
    {
        return $this->comingSoon($organization, __('navigation.org_admin_blog'));
    }

    public function categories(Organization $organization): View
    {
        return $this->comingSoon($organization, __('navigation.org_admin_categories'));
    }

    public function users(Organization $organization): View
    {
        return $this->comingSoon($organization, __('navigation.org_admin_users'));
    }

    public function reports(Organization $organization): View
    {
        return $this->comingSoon($organization, __('navigation.org_admin_reports'));
    }

    public function invitations(Organization $organization): View
    {
        return $this->comingSoon($organization, __('navigation.org_admin_invitations'));
    }

    public function translations(Organization $organization): View
    {
        return $this->comingSoon($organization, __('navigation.org_admin_translations'));
    }

    public function aiSupervision(Organization $organization): View
    {
        return $this->comingSoon($organization, __('navigation.org_admin_ai_supervision'));
    }

    public function memberAiProfiles(Organization $organization): View
    {
        return $this->comingSoon($organization, __('navigation.org_admin_member_ai_profiles'));
    }

    public function aiInteractions(Organization $organization): View
    {
        return $this->comingSoon($organization, __('navigation.org_admin_ai_interactions'));
    }

    private function comingSoon(Organization $organization, string $sectionName): View
    {
        return view('admin.org.coming-soon', [
            'organization' => $organization,
            'sectionName' => $sectionName,
        ]);
    }
}
