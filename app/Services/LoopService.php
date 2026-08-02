<?php

namespace App\Services;

use App\Models\Loop;
use App\Models\LoopJoinRequest;
use App\Models\LoopMember;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LoopService
{
    public function createLoopForOrg(User $user, string $organizationId, string $name, ?string $description = null, string $visibility = 'private', ?string $tagline = null, string $accessMode = Loop::ACCESS_REQUEST): Loop
    {
        $slug = $this->generateUniqueSlug($organizationId, $name);

        $loop = Loop::create([
            'organization_id' => $organizationId,
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'tagline' => $tagline,
            'type' => 'custom',
            'status' => 'active',
            'visibility' => $visibility,
            'access_mode' => Loop::isValidAccessMode($accessMode) ? $accessMode : Loop::ACCESS_REQUEST,
            'created_by' => $user->id,
        ]);

        $this->addMember($loop, $user, 'owner');

        return $loop;
    }

    public function createLoop(User $user, string $name, ?string $description = null, string $visibility = 'private', ?string $tagline = null, string $accessMode = Loop::ACCESS_REQUEST, ?string $coverImagePath = null): Loop
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
            'tagline' => $tagline,
            'cover_image_path' => $coverImagePath,
            'type' => 'custom',
            'status' => 'active',
            'visibility' => $visibility,
            'access_mode' => Loop::isValidAccessMode($accessMode) ? $accessMode : Loop::ACCESS_REQUEST,
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
        $user = User::assignable()->findOrFail($userId);

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
            'organization_id' => $loop->organization_id,
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
        if ($user->is_deactivated) {
            throw new \RuntimeException(__('validation.exists', ['attribute' => 'user']));
        }

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
            'organization_id' => $loop->organization_id,
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
                    ->assignable()
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

    public function joinOpenLoop(Loop $loop, User $user): LoopMember
    {
        $existing = LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            if ($existing->status !== 'active') {
                $existing->update(['status' => 'active', 'joined_at' => now()]);
            }

            return $existing->fresh();
        }

        return LoopMember::create([
            'loop_id' => $loop->id,
            'user_id' => $user->id,
            'role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
            'organization_id' => $loop->organization_id,
        ]);
    }

    public function requestToJoin(Loop $loop, User $user, ?string $message = null): LoopJoinRequest
    {
        return DB::transaction(function () use ($loop, $user, $message) {
            $existingPending = LoopJoinRequest::where('loop_id', $loop->id)
                ->where('user_id', $user->id)
                ->where('status', LoopJoinRequest::STATUS_PENDING)
                ->lockForUpdate()
                ->exists();

            if ($existingPending) {
                throw new \RuntimeException('A pending join request already exists for this loop.');
            }

            return LoopJoinRequest::create([
                'organization_id' => $loop->organization_id,
                'loop_id' => $loop->id,
                'user_id' => $user->id,
                'message' => $message,
                'status' => LoopJoinRequest::STATUS_PENDING,
            ]);
        });
    }

    public function cancelJoinRequest(LoopJoinRequest $joinRequest): void
    {
        if (! $joinRequest->isPending()) {
            throw new \RuntimeException('Only a pending join request can be cancelled.');
        }

        $joinRequest->update(['status' => LoopJoinRequest::STATUS_CANCELLED]);
    }

    public function acceptJoinRequest(LoopJoinRequest $joinRequest, User $decidedBy): LoopMember
    {
        return DB::transaction(function () use ($joinRequest, $decidedBy) {
            $joinRequest = LoopJoinRequest::where('id', $joinRequest->id)->lockForUpdate()->firstOrFail();

            if (! $joinRequest->isPending()) {
                throw new \RuntimeException('This join request has already been decided.');
            }

            $existingMember = LoopMember::where('loop_id', $joinRequest->loop_id)
                ->where('user_id', $joinRequest->user_id)
                ->first();

            if ($existingMember) {
                if ($existingMember->status !== 'active') {
                    $existingMember->update(['status' => 'active', 'joined_at' => now()]);
                }
                $member = $existingMember->fresh();
            } else {
                $member = LoopMember::create([
                    'loop_id' => $joinRequest->loop_id,
                    'user_id' => $joinRequest->user_id,
                    'role' => 'member',
                    'status' => 'active',
                    'joined_at' => now(),
                    'organization_id' => $joinRequest->organization_id,
                ]);
            }

            $joinRequest->update([
                'status' => LoopJoinRequest::STATUS_ACCEPTED,
                'decided_by' => $decidedBy->id,
                'decided_at' => now(),
            ]);

            return $member;
        });
    }

    public function rejectJoinRequest(LoopJoinRequest $joinRequest, User $decidedBy): void
    {
        DB::transaction(function () use ($joinRequest, $decidedBy) {
            $joinRequest = LoopJoinRequest::where('id', $joinRequest->id)->lockForUpdate()->firstOrFail();

            if (! $joinRequest->isPending()) {
                throw new \RuntimeException('This join request has already been decided.');
            }

            $joinRequest->update([
                'status' => LoopJoinRequest::STATUS_REJECTED,
                'decided_by' => $decidedBy->id,
                'decided_at' => now(),
            ]);
        });
    }

    public function archiveLoop(Loop $loop): Loop
    {
        $loop->update(['status' => 'archived']);

        return $loop;
    }

    public function restoreLoop(Loop $loop): Loop
    {
        $loop->update(['status' => 'active']);

        return $loop;
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
