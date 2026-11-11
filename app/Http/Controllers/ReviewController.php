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

        // TASK-1808 §5 — l'ecran A enregistre la note IMMEDIATEMENT, « sans
        // attendre un commentaire ». Il n'y a donc pas de message de
        // felicitation ici : l'ecran B prend la suite et poserait sa question
        // par-dessus un flash de fin. Le flash n'arrive qu'au bout du
        // parcours, et c'est l'ecran B qui le pose.
        return $this->retourAuFil($transaction);
    }

    /**
     * TASK-1808 §5, micro-ecran B — « Publier mon commentaire ».
     *
     * Complete une note DEJA enregistree. Ne cree jamais d'avis : si l'ecran A
     * n'a pas eu lieu, il n'y a rien a completer et la demande repart sans
     * rien ecrire. Ne touche jamais aux points — le transfert a eu lieu a la
     * cloture, bien avant, et une seule fois.
     */
    public function comment(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->authorize('complete-review', $transaction);

        $data = $request->validate([
            'comment' => 'nullable|string|max:1000',
        ]);

        $ecrit = EvaluationEntraide::completerLeMot($transaction, $data['comment'] ?? null);

        // Publier un mot vide, c'est terminer sans commentaire : on ne laisse
        // pas la question revenir indefiniment.
        if (! $ecrit) {
            EvaluationEntraide::renoncerAuMot($transaction);
        }

        // « Apres publication, afficher une confirmation DISCRETE, sans
        // nouvelle sollicitation » (§5).
        return $this->retourAuFil($transaction)
            ->with('success', __('messages.review_sent'));
    }

    /**
     * TASK-1808 §5, micro-ecran B — « Terminer sans commentaire ».
     *
     * La note reste acquise ; seule la question du mot cesse de revenir. A ne
     * pas confondre avec `skip()`, qui renonce a TOUTE l'evaluation.
     */
    public function skipComment(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->authorize('complete-review', $transaction);

        EvaluationEntraide::renoncerAuMot($transaction);

        return $this->retourAuFil($transaction);
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

        return $this->retourAuFil($transaction);
    }

    /**
     * TASK-1808 — le retour au fil garde le SCOPE de l'Organization.
     *
     * Les quatre actions existaient en double (globale et
     * `organization.*`) mais redirigeaient toutes vers la route globale :
     * depuis `/org/<slug>/...`, evaluer faisait donc SORTIR du scope. Meme
     * forme que `TransactionController::redirectToMessage()`, pour qu'il n'y
     * ait qu'une facon de revenir au fil dans tout le domaine entraide.
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
