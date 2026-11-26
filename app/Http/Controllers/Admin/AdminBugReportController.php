<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BugReport;
use App\Models\BugReportAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminBugReportController extends Controller
{
    public const STATUSES = ['pending', 'fixed', 'resolved', 'dismissed'];

    /**
     * TASK-1904 — « Signalements », back-office SUPERADMIN (`AdminMiddleware`).
     * Ceux « Securite des enfants » encore en attente passent en tete.
     *
     * TASK-1905 (decision MASTER D3) — ces derniers ne sont lus et traites que
     * par qui passe la Gate `manage-child-safety-reports` (superadmin strict,
     * comptes de demonstration exclus en production). Pour tout autre compte
     * `is_admin`, ils n'existent pas : ni liste, ni compteur, ni action (404).
     */
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'category' => ['nullable', 'string', Rule::in(BugReport::CATEGORIES)],
            'status' => ['nullable', 'string', Rule::in(self::STATUSES)],
        ]);

        $canChildSafety = Gate::allows('manage-child-safety-reports');

        $bugReports = BugReport::with(['organization', 'reporter', 'actions.actor'])
            ->when(! $canChildSafety, fn ($query) => $query->visibleByOrganizationAdmin())
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category', $category))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByRaw('CASE WHEN category = ? AND status = ? THEN 0 ELSE 1 END', [BugReport::CHILD_SAFETY, 'pending'])
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $pendingChildSafety = $canChildSafety
            ? BugReport::where('category', BugReport::CHILD_SAFETY)->where('status', 'pending')->count()
            : 0;

        // Alerte technique explicite : l'adresse d'alerte n'est pas configuree.
        $alertNotConfigured = $canChildSafety && trim((string) config('child_safety.alert_email')) === '';

        return view('admin.bug-reports', compact('bugReports', 'pendingChildSafety', 'filters', 'canChildSafety', 'alertNotConfigured'));
    }

    public function fix(Request $request, BugReport $bugReport): RedirectResponse
    {
        $this->assertCanHandle($bugReport);

        $data = $request->validate([
            'resolution_notes' => ['nullable', 'string', 'max:2000'],
            'internal_note' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($bugReport, $data, $request): void {
            $bugReport->update([
                'status' => 'fixed',
                // Reponse lue par l'AUTEUR du signalement (TASK-1904).
                'resolution_notes' => $data['resolution_notes'] ?? null,
                'fixed_at' => now(),
            ]);
            // Note INTERNE, jamais montree aux membres (TASK-1905, D5).
            $bugReport->recordAction(BugReportAction::FIXED, $request->user(), $data['internal_note'] ?? null);
        });

        return back()->with('success', 'Signalement marqué comme traité.');
    }

    public function dismiss(Request $request, BugReport $bugReport): RedirectResponse
    {
        $this->assertCanHandle($bugReport);

        $data = $request->validate([
            'internal_note' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($bugReport, $data, $request): void {
            // TASK-1905 — « Classer » date desormais le traitement.
            $bugReport->update(['status' => 'dismissed', 'fixed_at' => now()]);
            $bugReport->recordAction(BugReportAction::DISMISSED, $request->user(), $data['internal_note'] ?? null);
        });

        return back()->with('success', 'Signalement classé.');
    }

    public function destroy(BugReport $bugReport): RedirectResponse
    {
        $this->assertCanHandle($bugReport);

        // TASK-1905 (decision MASTER D4) — jamais de suppression definitive
        // d'un signalement « Securite des enfants » (garde aussi dans le modele).
        abort_if($bugReport->isChildSafety(), 403, 'Un signalement « Sécurité des enfants » ne peut pas être supprimé.');

        $bugReport->delete();

        return back()->with('success', 'Signalement supprimé.');
    }

    /** TASK-1905 (D3) — un `child_safety` n'existe pas pour qui ne passe pas la Gate. */
    private function assertCanHandle(BugReport $bugReport): void
    {
        abort_if($bugReport->isChildSafety() && ! Gate::allows('manage-child-safety-reports'), 404);
    }
}
