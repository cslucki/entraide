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
            $this->badgeService->checkAndAward($transaction->buyer);
            $this->badgeService->checkAndAward($transaction->seller);
        }
    }
}
