<?php

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;

class ReviewPolicy
{
    public function create(User $user, Transaction $transaction): bool
    {
        // Doit être participant de la transaction complétée
        $isParticipant = $user->id === $transaction->buyer_id || $user->id === $transaction->seller_id;

        return $isParticipant
            && $transaction->status === 'completed'
            && !$transaction->hasReviewFrom($user->id);
    }
}
