<?php

namespace App\Services;

use App\Models\LoopPermissionSetting;
use App\Models\Organization;
use App\Support\Loops\LoopRoleRegistry;
use App\Support\Loops\LoopTypeRegistry;

/**
 * The only place that reads or writes permission overrides.
 *
 * Controllers, policies and views never touch `organizations.loop_permissions`
 * nor `loop_permission_settings` directly: every path goes through here, so
 * validation, alias normalisation and the locked-permission refusal cannot be
 * bypassed by a forgotten caller.
 *
 * Storage is sparse throughout. An absent value means "inherit"; returning to
 * inheritance deletes the entry rather than writing a default, and empty
 * containers are pruned so the JSON never accumulates hollow scaffolding.
 */
class LoopPermissionSettingsService
{
    public function __construct(
        private LoopTypeRegistry $types,
        private LoopRoleRegistry $roles,
    ) {}

    // ── Catalogue ───────────────────────────────────────────────────────────

    /** @return array<string, array<string, mixed>> */
    public function permissions(): array
    {
        return config('loop_permissions.permissions', []);
    }

    public function permissionExists(string $permission): bool
    {
        return array_key_exists($permission, $this->permissions());
    }

    /**
     * A locked permission is a real capability, shown in the matrix, that no
     * global setting or Organization override may change.
     */
    public function isLocked(string $permission): bool
    {
        return (bool) ($this->permissions()[$permission]['locked'] ?? false);
    }

    /**
     * Card this permission depends on, if any.
     *
     * Array access, never config() dot-notation: card keys contain a dot
     * ("core.manifesto"), which dot-notation would split into a nested lookup
     * that never resolves — the bug fixed in CP5bis.
     */
    public function requiredCard(string $permission): ?string
    {
        $card = $this->permissions()[$permission]['requires_card'] ?? null;

        if ($card === null) {
            return null;
        }

        // A key retired from the card catalogue must not silently deny a
        // capability, so an unknown one is treated as "no dependency".
        return array_key_exists($card, config('loop_cards.cards', [])) ? $card : null;
    }

    /** True when type, role and permission are all known and writable. */
    public function isWritable(string $type, string $role, string $permission): bool
    {
        return $this->types->exists($type)
            && $this->roles->isCanonical($role)
            && $this->permissionExists($permission)
            && ! $this->isLocked($permission);
    }

    // ── Réglages globaux ────────────────────────────────────────────────────

    /** @return bool|null null when no explicit global override exists. */
    public function globalOverride(string $type, string $role, string $permission): ?bool
    {
        if (! $this->isWritable($type, $role, $permission)) {
            return null;
        }

        $row = LoopPermissionSetting::where('loop_type', $this->types->resolve($type))
            ->where('loop_role', $this->roles->canonical($role))
            ->where('permission', $permission)
            ->first();

        return $row?->allowed;
    }

    /** Writes an explicit global override. Refuses anything not writable. */
    public function setGlobal(string $type, string $role, string $permission, bool $allowed): bool
    {
        if (! $this->isWritable($type, $role, $permission)) {
            return false;
        }

        LoopPermissionSetting::updateOrCreate(
            [
                'loop_type' => $this->types->resolve($type),
                'loop_role' => $this->roles->canonical($role),
                'permission' => $permission,
            ],
            ['allowed' => $allowed],
        );

        return true;
    }

    /** Back to the registry default: the row is deleted, not set to a value. */
    public function clearGlobal(string $type, string $role, string $permission): void
    {
        LoopPermissionSetting::where('loop_type', $this->types->resolve($type))
            ->where('loop_role', $this->roles->canonical($role))
            ->where('permission', $permission)
            ->delete();
    }

    // ── Surcharges Organization ─────────────────────────────────────────────

    /** @return bool|null null when this Organization inherits. */
    public function organizationOverride(Organization $organization, string $type, string $role, string $permission): ?bool
    {
        if (! $this->isWritable($type, $role, $permission)) {
            return null;
        }

        $value = ($organization->loop_permissions ?? [])[$this->types->resolve($type)][$this->roles->canonical($role)][$permission] ?? null;

        return is_bool($value) ? $value : null;
    }

    public function setOrganization(Organization $organization, string $type, string $role, string $permission, bool $allowed): bool
    {
        if (! $this->isWritable($type, $role, $permission)) {
            return false;
        }

        $tree = $this->normalize($organization->loop_permissions ?? []);
        $tree[$this->types->resolve($type)][$this->roles->canonical($role)][$permission] = $allowed;

        $organization->update(['loop_permissions' => $this->normalize($tree)]);

        return true;
    }

    /** Back to inheritance: the key is removed and empty containers pruned. */
    public function clearOrganization(Organization $organization, string $type, string $role, string $permission): void
    {
        $tree = $this->normalize($organization->loop_permissions ?? []);
        $t = $this->types->resolve($type);
        $r = $this->roles->canonical($role);

        unset($tree[$t][$r][$permission]);

        $organization->update(['loop_permissions' => $this->normalize($tree)]);
    }

    /**
     * Drops anything unknown, non-boolean or locked, and prunes containers left
     * empty. Applied on both read and write, so a payload hand-edited in the
     * database cannot smuggle a key past validation either.
     *
     * @param  array<mixed>  $tree
     * @return array<string, array<string, array<string, bool>>>
     */
    public function normalize(array $tree): array
    {
        $clean = [];

        foreach ($tree as $type => $roles) {
            if (! is_array($roles) || ! $this->types->exists($type)) {
                continue;
            }

            foreach ($roles as $role => $permissions) {
                if (! is_array($permissions) || ! $this->roles->isCanonical($role)) {
                    continue;
                }

                foreach ($permissions as $permission => $allowed) {
                    if (! is_bool($allowed) || ! $this->isWritable($type, $role, $permission)) {
                        continue;
                    }

                    $clean[$type][$role][$permission] = $allowed;
                }
            }
        }

        return $clean;
    }
}
