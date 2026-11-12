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

    private function resourceBelongsToCurrentOrganization($resource): bool
    {
        $org = app()->bound('current_organization') ? app('current_organization') : null;

        if ($org === null) {
            return false;
        }

        return $resource->organization_id === $org->id;
    }
}
