<?php

namespace App\Observers;

use App\Models\Transaction;
use App\Services\BadgeService;

class TransactionObserver
{
    public function __construct(private BadgeService $badgeService) {}

    public function updated(Transaction $transaction): void
    {
        if ($transaction->wasChanged('status') && $transaction->status === 'completed') {
            // TASK-1757 — l'Organization vient de la TRANSACTION, pas du
            // contexte ambiant. Un observateur peut tourner dans une file ou
            // une commande, ou `currentOrganization()` est nul : les badges
            // d'entraide seraient alors silencieusement sautes.
            $organization = $transaction->organization;

            $this->badgeService->checkAndAward($transaction->buyer, $organization);
            $this->badgeService->checkAndAward($transaction->seller, $organization);
        }
    }
}
