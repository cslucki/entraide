<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
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
}
