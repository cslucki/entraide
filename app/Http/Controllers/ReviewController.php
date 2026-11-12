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
     *
     * TASK-1809 §2 — UNE seule porte d'entree desormais : la carte
     * compacte envoie `rating` ET `comment` dans la MEME requete (le
     * controleur acceptait deja les deux, voir T1806 §3.B — seul le
     * formulaire ne l'exposait pas). Les anciennes portes `defer()`,
     * `comment()`, `skipComment()` et `skip()` (ecrans separes, « Plus
     * tard », renoncement explicite) sont retirees avec le parcours visible
     * qui les appelait — MASTER : « reduire les clics et confirmations
     * superflus ». `EvaluationEntraide::enregistrer(..., null)` (le
     * renoncement) reste disponible en domaine pour un futur usage
     * administratif, mais plus aucune route web ne l'atteint.
     */
    public function store(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->authorize('create-review', $transaction);

        $data = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        EvaluationEntraide::enregistrer($transaction, (int) $data['rating'], $data['comment'] ?? null);

        return $this->retourAuFil($transaction)
            ->with('success', __('messages.review_sent'));
    }

    /**
     * TASK-1808 — le retour au fil garde le SCOPE de l'Organization.
     *
     * Meme forme que `TransactionController::redirectToMessage()`, pour qu'il
     * n'y ait qu'une facon de revenir au fil dans tout le domaine entraide.
     */
    private function retourAuFil(Transaction $transaction): RedirectResponse
    {
        $organization = request()->route('organization');

        if ($organization) {
            return redirect()->route('organization.messages.show', [
                'organization' => $organization,
                'transaction' => $transaction,
            ]);
        }

        return redirect()->route('messages.show', $transaction);
    }
}
