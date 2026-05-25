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

        $orgId = $organizationId ?? $referred->organization_id ?? $referred->community_id;

        if (! $orgId) {
            throw new \RuntimeException('Organization context required for referral.');
        }

        if ($referrer->organization_id !== $orgId) {
            throw new \RuntimeException('Cross-organization referral is not allowed.');
        }

        if ($referred->organization_id !== $orgId) {
            throw new \RuntimeException('Cross-organization referral is not allowed.');
        }

        $circular = Referral::where('organization_id', $orgId)
            ->where('referrer_user_id', $referred->id)
            ->where('referred_user_id', $referrer->id)
            ->exists();

        if ($circular) {
            throw new \RuntimeException('Circular referral is not allowed.');
        }

        $duplicate = Referral::where('organization_id', $orgId)
            ->where('referrer_user_id', $referrer->id)
            ->where('referred_user_id', $referred->id)
            ->exists();

        if ($duplicate) {
            throw new \RuntimeException('Duplicate referral is not allowed.');
        }

        event(new MemberInvited($referrer, $referred, $orgId, $metadata));

        $referral = Referral::where('organization_id', $orgId)
            ->where('referrer_user_id', $referrer->id)
            ->where('referred_user_id', $referred->id)
            ->first();

        if (! $referral) {
            throw new \RuntimeException('Referral creation failed unexpectedly.');
        }

        return $referral;
    }
}
