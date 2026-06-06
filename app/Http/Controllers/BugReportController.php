<?php

namespace App\Http\Controllers;

use App\Models\BugReport;
use App\Support\Tenancy\DefaultOrganizationResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class BugReportController extends Controller
{
    public function index(): View
    {
        $organization = app()->bound('current_organization')
            ? app('current_organization')
            : DefaultOrganizationResolver::resolve();

        $bugReports = BugReport::with('organization')
            ->when($organization, fn ($query) => $query->where('organization_id', $organization->id))
            ->whereIn('status', ['pending', 'fixed'])
            ->latest()
            ->paginate(20);

        return view('bug-reports.index', compact('bugReports', 'organization'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'details' => ['required', 'string', 'max:2000'],
            'page_url' => ['nullable', 'url', 'max:2048'],
        ]);

        $organization = app()->bound('current_organization')
            ? app('current_organization')
            : DefaultOrganizationResolver::resolve();

        if (! $organization) {
            return back()->with('error', 'Impossible de rattacher ce bug à une organisation.');
        }

        BugReport::create([
            'organization_id' => $organization->id,
            'reporter_id' => $request->user()->id,
            'reason' => $data['reason'],
            'details' => $data['details'],
            'page_url' => $data['page_url'] ?? url()->previous(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
        ]);

        return back()->with('success', 'Bug signalé. Merci pour votre aide !');
    }
}
