<?php

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;

class ReviewPolicy
{
    /**
     * TASK-1806 — une seule evaluatrice possible : l'aide, jamais l'aidant.
     *
     * Avant ce correctif, la garde ne verifiait que « participant a la
     * transaction » (buyer OU seller) — exactement le defaut qui laissait
     * l'aidant evaluer l'aide, dans les deux sens du parcours (demande comme
     * proposition). `estLAide()` resout le role depuis les colonnes
     * canoniques (`buyer_id`), jamais depuis qui a initie la transaction.
     */
    public function create(User $user, Transaction $transaction): bool
    {
        return $this->resourceBelongsToCurrentOrganization($transaction)
            && $transaction->estLAide($user)
            && $transaction->status === 'completed'
            && ! $transaction->hasReviewFrom($user->id)
            && ! $transaction->evaluationIgnoree();
    }

    /**
     * TASK-1808 §5 — le micro-ecran B, et il lui fallait sa PROPRE garde.
     *
     * `create()` refuse des qu'un avis existe (`! hasReviewFrom`) : c'est sa
     * raison d'etre, et la reutiliser ici aurait refuse le mot a toute note
     * deja posee — c'est-a-dire toujours. Les conditions s'inversent donc :
     * l'avis doit EXISTER, et le mot ne doit pas avoir ete decline.
     *
     * Le reste est identique a `create()`, et deliberement : meme role (l'aide
     * seul), meme Organization, meme exigence d'entraide close.
     */
    public function complete(User $user, Transaction $transaction): bool
    {
        return $this->resourceBelongsToCurrentOrganization($transaction)
            && $transaction->estLAide($user)
            && $transaction->status === 'completed'
            && $transaction->hasReviewFrom($user->id)
            && $transaction->motEnAttente();
    }

    private function resourceBelongsToCurrentOrganization($resource): bool
    {
        $org = app()->bound('current_organization') ? app('current_organization') : null;

        if ($org === null) {
            return false;
        }

        return $resource->organization_id === $org->id;
    }
}
