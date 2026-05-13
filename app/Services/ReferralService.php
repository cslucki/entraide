<?php

namespace App\Services;

use App\Events\MemberInvited;
use App\Models\Referral;
use App\Models\User;

class ReferralService
{
    public function attributeByCode(User $referred, string $code, ?string $organizationId = null, array $metadata = []): Referral
    {
        $referrer = User::where('referral_code', $code)->first();

        if (! $referrer) {
            throw new \RuntimeException('Invalid referral code.');
        }

        if ($referrer->is($referred)) {
            throw new \RuntimeException('Self-referral is not allowed.');
        }

        $communityId = $organizationId ?? $referred->community_id;

        if (! $communityId) {
            throw new \RuntimeException('Organization context required for referral.');
        }

        if ($referrer->community_id !== $communityId) {
            throw new \RuntimeException('Cross-organization referral is not allowed.');
        }

        if ($referred->community_id !== $communityId) {
            throw new \RuntimeException('Cross-organization referral is not allowed.');
        }

        $circular = Referral::where('community_id', $communityId)
            ->where('referrer_user_id', $referred->id)
            ->where('referred_user_id', $referrer->id)
            ->exists();

        if ($circular) {
            throw new \RuntimeException('Circular referral is not allowed.');
        }

        $duplicate = Referral::where('community_id', $communityId)
            ->where('referrer_user_id', $referrer->id)
            ->where('referred_user_id', $referred->id)
            ->exists();

        if ($duplicate) {
            throw new \RuntimeException('Duplicate referral is not allowed.');
        }

        event(new MemberInvited($referrer, $referred, $communityId, $metadata));

        $referral = Referral::where('community_id', $communityId)
            ->where('referrer_user_id', $referrer->id)
            ->where('referred_user_id', $referred->id)
            ->first();

        if (! $referral) {
            throw new \RuntimeException('Referral creation failed unexpectedly.');
        }

        return $referral;
    }
}
