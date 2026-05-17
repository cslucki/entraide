<?php

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;

class MessagePolicy
{
    public function view(User $user, Transaction $transaction): bool
    {
        return $this->resourceBelongsToCurrentOrganization($transaction)
            && ($user->id === $transaction->buyer_id || $user->id === $transaction->seller_id);
    }

    public function store(User $user, Transaction $transaction): bool
    {
        return $this->resourceBelongsToCurrentOrganization($transaction)
            && ($user->id === $transaction->buyer_id || $user->id === $transaction->seller_id)
            && ! in_array($transaction->status, ['completed', 'refused', 'cancelled']);
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
