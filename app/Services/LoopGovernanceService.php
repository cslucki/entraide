<?php

namespace App\Services;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Support\Loops\LoopRoleRegistry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every transition of a Loop role passes through here.
 *
 * The invariant this class exists to hold: a Loop always keeps at least one
 * active owner. It is checked on every path that could break it — demotion,
 * removal, voluntary departure, membership deactivation — because guarding only
 * the obvious one leaves the others open.
 *
 * Each transition runs in a transaction and locks the Loop's memberships, so two
 * simultaneous demotions cannot both see "there is another owner" and leave the
 * Loop with none. SQLite does not really enforce lockForUpdate; the concurrency
 * test runs on PostgreSQL for that reason.
 *
 * Nothing else in the codebase writes loop_members.role.
 */
class LoopGovernanceService
{
    public const RESULT_OK = 'ok';

    public const RESULT_UNCHANGED = 'unchanged';

    public const RESULT_LAST_OWNER = 'last_owner';

    public const RESULT_NOT_IN_LOOP = 'not_in_loop';

    public const RESULT_INACTIVE = 'inactive';

    public const RESULT_INVALID_ROLE = 'invalid_role';

    public function __construct(private LoopRoleRegistry $roles) {}

    /** Active owners, counted on canonical roles so a legacy alias still counts. */
    public function countActiveOwners(Loop $loop): int
    {
        return LoopMember::where('loop_id', $loop->id)
            ->where('status', 'active')
            ->where('role', LoopRoleRegistry::OWNER)
            ->count();
    }

    /** @return Collection<int, LoopMember> */
    public function activeOwners(Loop $loop)
    {
        return LoopMember::where('loop_id', $loop->id)
            ->where('status', 'active')
            ->where('role', LoopRoleRegistry::OWNER)
            ->with('user')
            // `id` en second critere : `joined_at` est a la seconde, et des
            // proprietaires promus dans la meme seconde s'egaleraient.
            ->orderBy('joined_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * True when this membership is the only thing standing between the Loop and
     * having no owner at all.
     */
    public function isLastActiveOwner(LoopMember $member): bool
    {
        return $this->roles->canonical($member->role) === LoopRoleRegistry::OWNER
            && $member->status === 'active'
            && LoopMember::where('loop_id', $member->loop_id)
                ->where('status', 'active')
                ->where('role', LoopRoleRegistry::OWNER)
                ->where('id', '!=', $member->id)
                ->count() === 0;
    }

    /**
     * Move a membership to a canonical role.
     *
     * Handles every transition in one place — promotion and demotion alike —
     * because the guard is the same in both directions: the change must not
     * leave the Loop without an active owner.
     */
    public function changeRole(LoopMember $member, string $targetRole): string
    {
        if (! $this->roles->isCanonical($targetRole)) {
            return self::RESULT_INVALID_ROLE;
        }

        return DB::transaction(function () use ($member, $targetRole) {
            // Lock the whole Loop's memberships, not just this row: the decision
            // depends on how many *other* owners there are.
            $locked = LoopMember::where('loop_id', $member->loop_id)
                ->lockForUpdate()
                ->get();

            $fresh = $locked->firstWhere('id', $member->id);

            if (! $fresh) {
                return self::RESULT_NOT_IN_LOOP;
            }

            if ($fresh->status !== 'active') {
                return self::RESULT_INACTIVE;
            }

            $current = $this->roles->canonical($fresh->role);

            // Already canonical and on target: nothing to write. A stored legacy
            // alias still gets rewritten, so touching an old row canonicalises it.
            if ($current === $targetRole && $fresh->role === $targetRole) {
                return self::RESULT_UNCHANGED;
            }

            if ($current === LoopRoleRegistry::OWNER && $targetRole !== LoopRoleRegistry::OWNER) {
                $otherOwners = $locked
                    ->where('status', 'active')
                    ->where('id', '!=', $fresh->id)
                    ->filter(fn (LoopMember $m) => $this->roles->canonical($m->role) === LoopRoleRegistry::OWNER)
                    ->count();

                if ($otherOwners === 0) {
                    return self::RESULT_LAST_OWNER;
                }
            }

            $fresh->update(['role' => $targetRole]);

            return self::RESULT_OK;
        });
    }

    public function promoteToOwner(LoopMember $member): string
    {
        return $this->changeRole($member, LoopRoleRegistry::OWNER);
    }

    public function promoteToFacilitator(LoopMember $member): string
    {
        return $this->changeRole($member, LoopRoleRegistry::FACILITATOR);
    }

    public function demoteToMember(LoopMember $member): string
    {
        return $this->changeRole($member, LoopRoleRegistry::MEMBER);
    }

    /**
     * Remove someone from the Loop.
     *
     * Replaces the previous blanket refusal for any owner: an owner may be
     * removed as long as another active one remains. Only the last is protected.
     */
    public function removeMember(LoopMember $member): string
    {
        return DB::transaction(function () use ($member) {
            $locked = LoopMember::where('loop_id', $member->loop_id)->lockForUpdate()->get();
            $fresh = $locked->firstWhere('id', $member->id);

            if (! $fresh) {
                return self::RESULT_NOT_IN_LOOP;
            }

            if ($fresh->status !== 'active') {
                return self::RESULT_UNCHANGED;
            }

            if ($this->roles->canonical($fresh->role) === LoopRoleRegistry::OWNER) {
                $otherOwners = $locked
                    ->where('status', 'active')
                    ->where('id', '!=', $fresh->id)
                    ->filter(fn (LoopMember $m) => $this->roles->canonical($m->role) === LoopRoleRegistry::OWNER)
                    ->count();

                if ($otherOwners === 0) {
                    return self::RESULT_LAST_OWNER;
                }
            }

            $fresh->update(['status' => 'left']);

            return self::RESULT_OK;
        });
    }

    /**
     * Voluntary departure.
     *
     * Same rule as removal, and deliberately the same code path: a controller
     * mutating the membership directly would sidestep the invariant.
     */
    public function leave(Loop $loop, string $userId): string
    {
        $member = LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->first();

        if (! $member) {
            return self::RESULT_NOT_IN_LOOP;
        }

        return $this->removeMember($member);
    }

    /**
     * Any transition of an active membership to a non-active status.
     *
     * The single entry point for deactivation flows, so none of them can empty a
     * Loop of its owners by taking a different route.
     */
    public function deactivateMembership(LoopMember $member, string $status = 'left'): string
    {
        if ($status === 'active') {
            return self::RESULT_INVALID_ROLE;
        }

        return DB::transaction(function () use ($member, $status) {
            $locked = LoopMember::where('loop_id', $member->loop_id)->lockForUpdate()->get();
            $fresh = $locked->firstWhere('id', $member->id);

            if (! $fresh || $fresh->status !== 'active') {
                return self::RESULT_UNCHANGED;
            }

            if ($this->isLastActiveOwner($fresh)) {
                return self::RESULT_LAST_OWNER;
            }

            $fresh->update(['status' => $status]);

            return self::RESULT_OK;
        });
    }
}
