<?php

namespace App\Http\Controllers;

use App\Models\Review;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function store(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->authorize('create-review', $transaction);

        $data = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        $user = auth()->user();

        // La personne notée est l'autre participant
        $reviewedId = $user->id === $transaction->buyer_id
            ? $transaction->seller_id
            : $transaction->buyer_id;

        Review::create([
            'transaction_id' => $transaction->id,
            'reviewer_id' => $user->id,
            'reviewed_id' => $reviewedId,
            'rating' => $data['rating'],
            'comment' => $data['comment'] ?? null,
        ]);

        // Recalculer la note moyenne de l'utilisateur noté
        $transaction->load('buyer', 'seller');
        $reviewed = $user->id === $transaction->buyer_id ? $transaction->seller : $transaction->buyer;
        $reviewed->recalculateRating();

        return redirect()->route('messages.show', $transaction)
            ->with('success', 'Merci pour votre évaluation !');
    }
}
