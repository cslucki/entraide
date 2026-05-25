<?php

namespace App\Services;

use App\Events\MemberActivated;
use App\Events\MemberInvited;
use App\Models\PointLedger;
use App\Models\Referral;
use App\Models\ReferralReward;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RewardDispatcher
{
    public function handleInvited(MemberInvited $event): Referral
    {
        if ($event->referrer->is($event->referred)) {
            throw new \RuntimeException('Self-referral is not allowed.');
        }

        $orgId = $event->organizationId ?? $event->referrer->organization_id ?? $event->referrer->community_id;

        if (! $orgId) {
            throw new \RuntimeException('Organization context required for referral.');
        }

        if ($event->referrer->organization_id !== $orgId) {
            throw new \RuntimeException('Cross-organization referral is not allowed.');
        }

        if ($event->referred->organization_id !== $orgId) {
            throw new \RuntimeException('Cross-organization referral is not allowed.');
        }

        $exists = Referral::where('organization_id', $orgId)
            ->where('referrer_user_id', $event->referrer->id)
            ->where('referred_user_id', $event->referred->id)
            ->exists();

        if ($exists) {
            throw new \RuntimeException('Duplicate referral pair in this organization.');
        }

        return DB::transaction(function () use ($event, $orgId) {
            $referral = Referral::create([
                'organization_id' => $orgId,
                'referrer_user_id' => $event->referrer->id,
                'referred_user_id' => $event->referred->id,
                'depth' => 1,
                'status' => 'pending',
            ]);

            $this->award($referral, $event->referrer, $event->referred, 'member_invited', 1, (int) config('referral.rewards.invitation.level_1_referrer', 10), $event->metadata);
            $this->award($referral, $event->referred, $event->referrer, 'member_invited', 1, (int) config('referral.rewards.invitation.welcome', 10), $event->metadata);

            $this->handleL2Invite($referral, $orgId, $event);

            return $referral;
        });
    }

    public function handleActivated(MemberActivated $event): void
    {
        $referrals = Referral::where('referred_user_id', $event->user->id)
            ->where('status', 'pending')
            ->where('depth', 1)
            ->get();

        if ($referrals->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($referrals, $event) {
            foreach ($referrals as $referral) {
                $referral->update([
                    'status' => 'activated',
                    'activated_at' => now(),
                ]);

                assert($referral->referrer instanceof User);
                $this->award($referral, $referral->referrer, $event->user, 'member_activated', 1, (int) config('referral.rewards.activation.level_1_referrer', 20), $event->metadata);
                $this->handleL2Activation($referral, $event);
            }
        });
    }

    private function handleL2Invite(Referral $referral, ?string $orgId, MemberInvited $event): void
    {
        $parentReferral = Referral::where('referred_user_id', $event->referrer->id)
            ->where('status', 'activated')
            ->first();

        if (! $parentReferral) {
            return;
        }

        if ($parentReferral->referrer_user_id === $event->referred->id) {
            return;
        }

        $l2 = Referral::create([
            'organization_id' => $orgId,
            'referrer_user_id' => $parentReferral->referrer_user_id,
            'referred_user_id' => $event->referred->id,
            'parent_referral_id' => $referral->id,
            'depth' => 2,
            'status' => 'pending',
        ]);

        assert($l2->referrer instanceof User);
        $this->award($l2, $l2->referrer, $event->referred, 'member_invited', 2, (int) config('referral.rewards.invitation.level_2_referrer', 5), $event->metadata);
    }

    private function handleL2Activation(Referral $referral, MemberActivated $event): void
    {
        $l2 = Referral::where('parent_referral_id', $referral->id)
            ->where('depth', 2)
            ->first();

        if (! $l2) {
            return;
        }

        $l2->update([
            'status' => 'activated',
            'activated_at' => now(),
        ]);

        assert($l2->referrer instanceof User);
        $this->award($l2, $l2->referrer, $event->user, 'member_activated', 2, (int) config('referral.rewards.activation.level_2_referrer', 10), $event->metadata);
    }

    private function award(Referral $referral, User $user, User $source, string $eventType, int $level, int $points, ?array $metadata = null): ReferralReward
    {
        if ($referral->organization_id !== null) {
            $currentOrg = app()->bound('current_organization') ? app('current_organization') : null;
            if ($currentOrg && $referral->organization_id !== $currentOrg->id) {
                throw new \RuntimeException('Referral belongs to a different organization.');
            }
        }

        $reward = ReferralReward::create([
            'organization_id' => $referral->organization_id,
            'referral_id' => $referral->id,
            'user_id' => $user->id,
            'source_user_id' => $source->id,
            'event_type' => $eventType,
            'level' => $level,
            'points' => $points,
            'metadata' => $metadata,
        ]);

        $user->increment('points_balance', $points);

        PointLedger::create([
            'user_id' => $user->id,
            'transaction_id' => null,
            'delta' => $points,
            'reason' => 'referral_reward',
        ]);

        return $reward;
    }
}
