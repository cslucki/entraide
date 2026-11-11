<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Support\Entraide\EvaluationEntraide;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    /**
     * TASK-1806 — l'evaluation d'une entraide DEJA terminee.
     *
     * Depuis l'arbitrage du 11/11 (parcours a trois etapes), le chemin normal
     * d'une evaluation est la CONFIRMATION elle-meme
     * (`TransactionController::complete()`) : l'aide confirme et evalue dans
     * le meme geste, et il n'y a pas d'etape « evaluation » separee.
     *
     * Ce controleur sert les entraides closes AVANT cet arbitrage — `completed`
     * sans avis ni renoncement (7 lignes mesurees sur le jumeau le 11/11) —
     * que MASTER a voulu garder evaluables. Sans lui, elles ne pourraient plus
     * jamais l'etre et le rattrapage prevu en T1807 n'aurait plus de porte.
     *
     * Un seul evaluateur (l'aide), un seul evalue (l'aidant) : la regle est
     * portee par `ReviewPolicy::create()` et par `EvaluationEntraide`, partagee
     * avec le chemin de confirmation — une seule ecriture d'avis dans tout le
     * depot.
     */
    public function store(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->authorize('create-review', $transaction);

        $data = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        EvaluationEntraide::enregistrer($transaction, (int) $data['rating'], $data['comment'] ?? null);

        return redirect()->route('messages.show', $transaction)
            ->with('success', __('messages.review_sent'));
    }

    /**
     * « Possibilite de ne pas laisser d'avis », pour ces memes entraides
     * closes avant l'arbitrage. `reviews_skipped_at` distingue « pas encore
     * evalue » de « a choisi de ne pas evaluer » — aucune ligne `Review`
     * n'est creee, le `rating` NOT NULL n'est jamais contourne par une valeur
     * inventee.
     */
    public function skip(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->authorize('create-review', $transaction);

        EvaluationEntraide::enregistrer($transaction, null);

        return redirect()->route('messages.show', $transaction);
    }
}
