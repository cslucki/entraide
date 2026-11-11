<?php

namespace App\Http\Controllers;

use App\Models\Review;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    /**
     * TASK-1806 — un seul destinataire possible : l'aidant (`seller_id`).
     *
     * `ReviewPolicy::create()` garantit deja que seule l'aide (`buyer_id`)
     * atteint cette methode ; `reviewed_id` n'a donc plus besoin d'etre
     * calcule comme « l'autre participant » — il est fixe sur l'aidant, dans
     * les deux sens du parcours (demande comme proposition).
     */
    public function store(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->authorize('create-review', $transaction);

        $data = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        Review::create([
            'transaction_id' => $transaction->id,
            'organization_id' => $transaction->organization_id,
            'reviewer_id' => $transaction->buyer_id,
            'reviewed_id' => $transaction->seller_id,
            'rating' => $data['rating'],
            'comment' => $data['comment'] ?? null,
        ]);

        $transaction->seller->recalculateRating();

        return redirect()->route('messages.show', $transaction)
            ->with('success', __('messages.review_sent'));
    }

    /**
     * TASK-1806 §3.A — « possibilite de ne pas laisser d'avis ».
     *
     * `reviews_skipped_at` distingue « pas encore evalue » de « a choisi de
     * ne pas evaluer » (§3.B) — aucune ligne `Review` n'est creee, le rating
     * NOT NULL n'est jamais contourne par une valeur inventee.
     */
    public function skip(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->authorize('create-review', $transaction);

        $transaction->update(['reviews_skipped_at' => now()]);

        return redirect()->route('messages.show', $transaction);
    }
}
