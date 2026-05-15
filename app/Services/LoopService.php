<?php

namespace App\Services;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class LoopService
{
    public function createLoop(User $user, string $name, ?string $description = null): Loop
    {
        $communityId = $user->community_id;

        if (! $communityId) {
            throw new \RuntimeException('User has no community.');
        }

        $slug = $this->generateUniqueSlug($communityId, $name);

        $loop = Loop::create([
            'community_id' => $communityId,
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'type' => 'custom',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $this->addMember($loop, $user, 'owner');

        return $loop;
    }

    public function addMember(Loop $loop, User $user, string $role = 'member'): LoopMember
    {
        if ($loop->community_id !== $user->community_id) {
            throw new \RuntimeException('Cannot add member from a different community to this loop.');
        }

        $existing = LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            throw new \RuntimeException('User is already a member of this loop.');
        }

        return LoopMember::create([
            'loop_id' => $loop->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }

    public function getEligibleReferrals(User $user, Loop $loop): Collection
    {
        if ($loop->community_id !== $user->community_id) {
            return new Collection;
        }

        $existingMemberUserIds = LoopMember::where('loop_id', $loop->id)
            ->pluck('user_id');

        return Referral::where('referrer_user_id', $user->id)
            ->where('community_id', $loop->community_id)
            ->whereHas('referred', function ($q) use ($loop, $existingMemberUserIds) {
                $q->where('community_id', $loop->community_id)
                    ->whereNotIn('id', $existingMemberUserIds);
            })
            ->with('referred')
            ->get();
    }

    public function addReferralToLoop(Loop $loop, User $user, Referral $referral): LoopMember
    {
        if ($referral->referrer_user_id !== $user->id) {
            throw new \RuntimeException('This referral does not belong to you.');
        }

        if ($referral->community_id !== $loop->community_id) {
            throw new \RuntimeException('Cannot add cross-community referral to this loop.');
        }

        $referred = $referral->referred;

        if (! $referred) {
            throw new \RuntimeException('Referred user not found.');
        }

        return $this->addMember($loop, $referred);
    }

    private function generateUniqueSlug(string $communityId, string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;

        for ($i = 1; $i <= 20; $i++) {
            $exists = Loop::where('community_id', $communityId)
                ->where('slug', $slug)
                ->exists();

            if (! $exists) {
                return $slug;
            }

            $slug = $base . '-' . $i;
        }

        return $base . '-' . Str::lower(Str::random(4));
    }
}
