<?php

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;

class TransactionPolicy
{
    public function view(User $user, Transaction $transaction): bool
    {
        return $this->resourceBelongsToCurrentOrganization($transaction)
            && ($user->id === $transaction->buyer_id || $user->id === $transaction->seller_id);
    }

    /**
     * TASK-1693 bug S — decide celui qui n'a PAS propose.
     *
     * `seller_id` en dur designait le proposant lui-meme des que l'echange
     * naissait d'une demande d'aide : voir `Transaction::decisionMakerId()`.
     * Pour une offre de service, cette methode rend `seller_id` — le
     * comportement historique, a l'identique.
     */
    public function approve(User $user, Transaction $transaction): bool
    {
        return $this->resourceBelongsToCurrentOrganization($transaction)
            && $user->id === $transaction->decisionMakerId() && $transaction->status === 'pending';
    }

    /** Meme regle qu'`approve()` : refuser est l'autre face de la decision. */
    public function refuse(User $user, Transaction $transaction): bool
    {
        return $this->resourceBelongsToCurrentOrganization($transaction)
            && $user->id === $transaction->decisionMakerId() && $transaction->status === 'pending';
    }

    public function adjust(User $user, Transaction $transaction): bool
    {
        return $this->resourceBelongsToCurrentOrganization($transaction)
            && ($user->id === $transaction->buyer_id || $user->id === $transaction->seller_id)
            && $transaction->status === 'pending';
    }

    public function cancel(User $user, Transaction $transaction): bool
    {
        return $this->resourceBelongsToCurrentOrganization($transaction)
            && ($user->id === $transaction->buyer_id || $user->id === $transaction->seller_id)
            && in_array($transaction->status, ['pending', 'accepted']);
    }

    public function complete(User $user, Transaction $transaction): bool
    {
        return $this->resourceBelongsToCurrentOrganization($transaction)
            && $user->id === $transaction->buyer_id && $transaction->status === 'accepted';
    }

    public function confirm(User $user, Transaction $transaction): bool
    {
        return $this->resourceBelongsToCurrentOrganization($transaction)
            && $user->id === $transaction->seller_id && $transaction->status === 'buyer_done';
    }

    public function contest(User $user, Transaction $transaction): bool
    {
        return $this->resourceBelongsToCurrentOrganization($transaction)
            && $user->id === $transaction->seller_id && $transaction->status === 'buyer_done';
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
