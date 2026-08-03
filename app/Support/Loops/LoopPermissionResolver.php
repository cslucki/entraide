<?php

namespace App\Support\Loops;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopPermissionSettingsService;

/**
 * The single authority on what a user may do inside a Loop.
 *
 * Resolution runs in a fixed order, and the order is the design:
 *
 *   1. absolute guardrails and tenant coherence
 *   2. active, unbanned account
 *   3. explicit super-admin authority
 *   4. Organization admin authority, within their own Organization
 *   5. everyone else: an active membership is required
 *   6. canonical Loop role
 *   7. exact Organization override
 *   8. exact global setting
 *   9. system variation for the type
 *  10. system baseline for the role
 *  11. secure refusal
 *
 * Steps 3 and 4 sit *before* the membership requirement on purpose: a
 * super-admin and an Organization admin administer Loops they are not members
 * of. Neither ever bypasses tenant isolation, the last-owner protection or any
 * other invariant — those are enforced in the services, not here.
 *
 * Policies remain the entry points and delegate here, so no
 * `role === 'facilitator'` condition survives anywhere else.
 */
class LoopPermissionResolver
{
    public function __construct(
        private LoopRoleRegistry $roles,
        private LoopTypeRegistry $types,
        private LoopPermissionSettingsService $settings,
    ) {}

    public function can(User $user, Loop $loop, string $permission): bool
    {
        // 1 — the permission must exist at all. An unknown key is a refusal,
        // never an accidental grant.
        if (! $this->settings->permissionExists($permission)) {
            return false;
        }

        // 1 — tenant coherence. A Loop belongs to exactly one Organization, and
        // no authority crosses that line.
        if ($user->organization_id !== $loop->organization_id && ! $user->is_admin) {
            return false;
        }

        // 2 — account state.
        if ($user->isDeactivated()) {
            return false;
        }

        // Card dependency, where the permission genuinely declares one.
        if (! $this->cardIsAvailable($loop, $permission)) {
            return false;
        }

        // 3 — platform authority.
        if ($user->is_admin) {
            return true;
        }

        // 4 — Organization authority, within their own Organization only.
        if ($loop->organization_id === $user->organization_id
            && $loop->organization?->admin_id === $user->id) {
            return true;
        }

        // 5 — everyone else needs an active membership.
        $membership = LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if (! $membership) {
            return false;
        }

        // 6 — canonical role, so a legacy `moderator` row resolves here and
        // nowhere else.
        $role = $this->roles->canonical($membership->role);
        $type = $this->types->resolve($loop->type);

        return $this->resolveForRole($loop->organization, $type, $role, $permission);
    }

    /**
     * Steps 7 to 11, shared with the administration screens so what they show
     * is exactly what the resolver applies.
     *
     * @param  Organization|null  $organization
     */
    public function resolveForRole($organization, string $type, string $role, string $permission): bool
    {
        // A locked permission answers from the system baseline alone: no global
        // setting and no Organization override may move it.
        if ($this->settings->isLocked($permission)) {
            return $this->systemValue($type, $role, $permission);
        }

        // 7 — exact Organization override.
        if ($organization) {
            $override = $this->settings->organizationOverride($organization, $type, $role, $permission);

            if ($override !== null) {
                return $override;
            }
        }

        // 8 — exact global setting.
        $global = $this->settings->globalOverride($type, $role, $permission);

        if ($global !== null) {
            return $global;
        }

        // 9 and 10 — type variation over the role baseline.
        return $this->systemValue($type, $role, $permission);
    }

    /**
     * Steps 9, 10 and 11: the type's declared differences applied over the
     * role's baseline, then a secure refusal.
     */
    public function systemValue(string $type, string $role, string $permission): bool
    {
        $type = $this->types->resolve($type);
        $role = $this->roles->canonical($role);

        $baseline = config('loop_permissions.role_defaults.'.$role, []);
        $allowed = in_array($permission, $baseline, true);

        $override = config('loop_permissions.type_overrides', [])[$type][$role] ?? [];

        if (in_array($permission, $override['grant'] ?? [], true)) {
            $allowed = true;
        }

        if (in_array($permission, $override['revoke'] ?? [], true)) {
            $allowed = false;
        }

        return $allowed;
    }

    /**
     * Whether the card a permission depends on is active on this Loop.
     *
     * Only permissions that genuinely declare `requires_card` are affected —
     * never inferred from the name — so a structural capability such as
     * loops.manage_owners is unaffected by card composition.
     */
    private function cardIsAvailable(Loop $loop, string $permission): bool
    {
        $card = $this->settings->requiredCard($permission);

        if ($card === null) {
            return true;
        }

        return in_array($card, app(LoopTypeRegistry::class)->activeCardsFor($loop), true);
    }
}
