<?php

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;

class TransactionPolicy
{
    public function view(User $user, Transaction $transaction): bool
    {
        return $user->id === $transaction->buyer_id || $user->id === $transaction->seller_id;
    }

    public function approve(User $user, Transaction $transaction): bool
    {
        return $user->id === $transaction->seller_id && $transaction->status === 'pending';
    }

    public function refuse(User $user, Transaction $transaction): bool
    {
        return $user->id === $transaction->seller_id && $transaction->status === 'pending';
    }

    public function adjust(User $user, Transaction $transaction): bool
    {
        return ($user->id === $transaction->buyer_id || $user->id === $transaction->seller_id)
            && $transaction->status === 'pending';
    }

    public function cancel(User $user, Transaction $transaction): bool
    {
        return ($user->id === $transaction->buyer_id || $user->id === $transaction->seller_id)
            && in_array($transaction->status, ['pending', 'accepted']);
    }

    public function complete(User $user, Transaction $transaction): bool
    {
        return $user->id === $transaction->buyer_id && $transaction->status === 'accepted';
    }

    public function confirm(User $user, Transaction $transaction): bool
    {
        return $user->id === $transaction->seller_id && $transaction->status === 'buyer_done';
    }

    public function contest(User $user, Transaction $transaction): bool
    {
        return $user->id === $transaction->seller_id && $transaction->status === 'buyer_done';
    }
}
