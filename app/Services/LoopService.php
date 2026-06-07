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
    public function createLoop(User $user, string $name, ?string $description = null, string $visibility = 'private'): Loop
    {
        $orgId = $user->organization_id;

        if (! $orgId) {
            throw new \RuntimeException('User has no organization.');
        }

        $slug = $this->generateUniqueSlug($orgId, $name);

        $loop = Loop::create([
            'organization_id' => $orgId,
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'type' => 'custom',
            'status' => 'active',
            'visibility' => $visibility,
            'created_by' => $user->id,
        ]);

        $this->addMember($loop, $user, 'owner');

        return $loop;
    }

    public function updateLoop(Loop $loop, array $data): Loop
    {
        $loop->update($data);

        return $loop;
    }

    public function addMemberByUserId(Loop $loop, string $userId, string $role = 'member'): LoopMember
    {
        $user = User::findOrFail($userId);

        if ($loop->organization_id !== $user->organization_id) {
            throw new \RuntimeException('Cannot add member from a different organization to this loop.');
        }

        $existing = LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $userId)
            ->first();

        if ($existing) {
            if ($existing->status === 'active') {
                throw new \RuntimeException('User is already a member of this loop.');
            }

            $existing->update(['status' => 'active', 'joined_at' => now()]);

            return $existing;
        }

        return LoopMember::create([
            'loop_id' => $loop->id,
            'user_id' => $userId,
            'role' => $role,
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }

    public function removeMember(LoopMember $member): void
    {
        if ($member->role === 'owner') {
            throw new \RuntimeException('Cannot remove the owner of a loop.');
        }

        $member->update(['status' => 'left']);
    }

    public function addMember(Loop $loop, User $user, string $role = 'member'): LoopMember
    {
        $orgId = $user->organization_id;

        if ($loop->organization_id !== $orgId) {
            throw new \RuntimeException('Cannot add member from a different organization to this loop.');
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
        $orgId = $user->organization_id;

        if ($loop->organization_id !== $orgId) {
            return new Collection;
        }

        $existingMemberUserIds = LoopMember::where('loop_id', $loop->id)
            ->pluck('user_id');

        return Referral::where('referrer_user_id', $user->id)
            ->where('organization_id', $loop->organization_id)
            ->whereHas('referred', function ($q) use ($loop, $existingMemberUserIds) {
                $q->where('organization_id', $loop->organization_id)
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

        if ($referral->organization_id !== $loop->organization_id) {
            throw new \RuntimeException('Cannot add cross-organization referral to this loop.');
        }

        $referred = $referral->referred;

        if (! $referred) {
            throw new \RuntimeException('Referred user not found.');
        }

        assert($referred instanceof User);

        return $this->addMember($loop, $referred);
    }

    private function generateUniqueSlug(string $orgId, string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;

        for ($i = 1; $i <= 20; $i++) {
            $exists = Loop::where('organization_id', $orgId)
                ->where('slug', $slug)
                ->exists();

            if (! $exists) {
                return $slug;
            }

            $slug = $base.'-'.$i;
        }

        return $base.'-'.Str::lower(Str::random(4));
    }
}
