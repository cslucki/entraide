<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAiInteraction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminAiReviewQueueController extends Controller
{
    public function index(): View
    {
        $interactions = AdminAiInteraction::needsReview()
            ->with(['user', 'reviewedBy'])
            ->orderBy('created_at', 'desc')
            ->paginate(25);

        return view('admin.ai-review-queue.index', [
            'interactions' => $interactions,
        ]);
    }

    public function update(Request $request, $id): RedirectResponse
    {
        $validated = $request->validate([
            'review_status' => 'required|in:approved,rejected',
            'review_notes' => 'nullable|string',
        ]);

        $interaction = AdminAiInteraction::findOrFail($id);

        $interaction->update([
            'review_status' => $validated['review_status'],
            'review_notes' => $validated['review_notes'] ?? null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return redirect()->route('admin.ai-review-queue')
            ->with('success', 'Interaction ' . ($validated['review_status'] === 'approved' ? 'approuvée' : 'rejetée') . '.');
    }
}
