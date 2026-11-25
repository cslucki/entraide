<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BugReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminBugReportController extends Controller
{
    public const STATUSES = ['pending', 'fixed', 'resolved', 'dismissed'];

    /**
     * TASK-1904 — « Signalements », back-office SUPERADMIN (`AdminMiddleware`).
     * Seul endroit ou les signalements « Securite des enfants » sont lus
     * (decision MASTER D3). Ceux encore en attente passent en tete.
     */
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'category' => ['nullable', 'string', Rule::in(BugReport::CATEGORIES)],
            'status' => ['nullable', 'string', Rule::in(self::STATUSES)],
        ]);

        $bugReports = BugReport::with(['organization', 'reporter'])
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category', $category))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByRaw('CASE WHEN category = ? AND status = ? THEN 0 ELSE 1 END', [BugReport::CHILD_SAFETY, 'pending'])
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $pendingChildSafety = BugReport::where('category', BugReport::CHILD_SAFETY)
            ->where('status', 'pending')
            ->count();

        return view('admin.bug-reports', compact('bugReports', 'pendingChildSafety', 'filters'));
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

        return back()->with('success', 'Signalement marqué comme traité.');
    }

    public function dismiss(BugReport $bugReport): RedirectResponse
    {
        $bugReport->update(['status' => 'dismissed']);

        return back()->with('success', 'Signalement classé.');
    }

    public function destroy(BugReport $bugReport): RedirectResponse
    {
        $bugReport->delete();

        return back()->with('success', 'Signalement supprimé.');
    }
}
