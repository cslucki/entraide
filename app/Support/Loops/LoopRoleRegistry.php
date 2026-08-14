<?php

namespace App\Support\Loops;

/**
 * The single place that knows what a Loop role is called.
 *
 * `facilitator` is canonical. `moderator` is a legacy alias, read-only: it is
 * normalised here and nowhere else, so no `moderator || facilitator` condition
 * survives in the codebase and no second business role exists implicitly.
 *
 * The alias costs nothing today — the audit found zero `moderator` rows in the
 * database — but it protects any environment where one might exist, and lets
 * the historical value keep rendering. It can be retired once every
 * environment is confirmed clean.
 */
class LoopRoleRegistry
{
    public const OWNER = 'owner';

    public const FACILITATOR = 'facilitator';

    public const MEMBER = 'member';

    /** Canonical roles, in descending order of authority. */
    public const CANONICAL = [self::OWNER, self::FACILITATOR, self::MEMBER];

    /**
     * Read-only aliases. Never written: every promotion writes a canonical
     * value, and updating an old row produces one too.
     */
    public const LEGACY_ALIASES = ['moderator' => self::FACILITATOR];

    /** @return array<int, string> */
    public function all(): array
    {
        return self::CANONICAL;
    }

    public function isCanonical(?string $role): bool
    {
        return $role !== null && in_array($role, self::CANONICAL, true);
    }

    /**
     * Resolve a stored value to a canonical role.
     *
     * Anything unrecognised reads as `member`: the least privileged role is the
     * only safe answer for a value we do not understand.
     */
    public function canonical(?string $role): string
    {
        if ($this->isCanonical($role)) {
            return $role;
        }

        return self::LEGACY_ALIASES[$role] ?? self::MEMBER;
    }

    public function isLegacyAlias(?string $role): bool
    {
        return $role !== null && array_key_exists($role, self::LEGACY_ALIASES);
    }

    public function label(?string $role): string
    {
        return __('loops.members_role_'.$this->canonical($role));
    }
}
