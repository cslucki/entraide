<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BugReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminBugReportController extends Controller
{
    public function index(): View
    {
        $bugReports = BugReport::with(['organization', 'reporter'])
            ->latest()
            ->paginate(20);

        return view('admin.bug-reports', compact('bugReports'));
    }

    public function fix(Request $request, BugReport $bugReport): RedirectResponse
    {
        $data = $request->validate([
            'resolution_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $bugReport->update([
            'status' => 'fixed',
            'resolution_notes' => $data['resolution_notes'] ?? null,
            'fixed_at' => now(),
        ]);

        return back()->with('success', 'Bug marqué comme corrigé.');
    }

    public function dismiss(BugReport $bugReport): RedirectResponse
    {
        $bugReport->update(['status' => 'dismissed']);

        return back()->with('success', 'Bug classé.');
    }

    public function destroy(BugReport $bugReport): RedirectResponse
    {
        $bugReport->delete();

        return back()->with('success', 'Bug supprimé.');
    }
}
