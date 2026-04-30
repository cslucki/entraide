<?php

namespace App\Policies;

use App\Models\Message;
use App\Models\Transaction;
use App\Models\User;

class MessagePolicy
{
    /**
     * Détermine si l'utilisateur peut voir le message.
     */
    public function view(User $user, Message $message): bool
    {
        return $user->id === $message->transaction->buyer_id || $user->id === $message->transaction->seller_id;
    }

    /**
     * Détermine si l'utilisateur peut créer un message dans une transaction.
     */
    public function create(User $user, Transaction $transaction): bool
    {
        return ($user->id === $transaction->buyer_id || $user->id === $transaction->seller_id)
            && !in_array($transaction->status, ['completed', 'refused', 'cancelled']);
    }
}
