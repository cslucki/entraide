<?php

namespace App\Listeners;

use App\Events\MemberActivated;
use App\Events\MemberInvited;
use App\Services\RewardDispatcher;

class AwardReferralReward
{
    public function __construct(
        private RewardDispatcher $dispatcher,
    ) {}

    public function handle(MemberInvited|MemberActivated $event): void
    {
        if ($event instanceof MemberInvited) {
            $this->dispatcher->handleInvited($event);
        } elseif ($event instanceof MemberActivated) {
            $this->dispatcher->handleActivated($event);
        }
    }
}
