<?php

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;

class MessagePolicy
{
    public function view(User $user, Transaction $transaction): bool
    {
        return $user->id === $transaction->buyer_id || $user->id === $transaction->seller_id;
    }

    public function store(User $user, Transaction $transaction): bool
    {
        return ($user->id === $transaction->buyer_id || $user->id === $transaction->seller_id)
            && !in_array($transaction->status, ['completed', 'refused', 'cancelled']);
    }
}
