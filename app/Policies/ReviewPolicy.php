<?php

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;

class ReviewPolicy
{
    public function create(User $user, Transaction $transaction): bool
    {
        $isParticipant = $user->id === $transaction->buyer_id || $user->id === $transaction->seller_id;

        return $this->resourceBelongsToCurrentOrganization($transaction)
            && $isParticipant
            && $transaction->status === 'completed'
            && ! $transaction->hasReviewFrom($user->id);
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
