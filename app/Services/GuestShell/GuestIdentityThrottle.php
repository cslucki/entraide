<?php

namespace App\Services\GuestShell;

use App\Models\Organization;
use Illuminate\Support\Facades\RateLimiter;

/**
 * TASK-1460 (Growth V3 §3, Shell Welcome V3 P1, audit OPUS F1) — anti-rafale
 * PRE-IDENTITE par Organization : avant qu'un premier geste public (tour du
 * Shell, interet pour une session) ne CREE un visiteur, l'Organization ne
 * peut accueillir qu'un nombre borne de nouvelles identites par minute.
 * L'identite du compteur est l'Organization — jamais l'IP (garde reseau,
 * jamais persistee, jamais identite). Un visiteur deja porteur de son cookie
 * n'est pas concerne : sa rafale a lui est celle de GuestShellGate.
 * Configuration absente ou invalide = fail-closed (aucune nouvelle identite).
 */
final class GuestIdentityThrottle
{
    public const REASON = 'identity_rate_limited';

    public static function key(Organization $organization): string
    {
        return 'guest-identity:'.$organization->getKey();
    }

    /** Vrai si une nouvelle identite peut naitre maintenant (et la compte). */
    public function allowNewIdentity(Organization $organization): bool
    {
        $perMinute = config('ai.guest_shell.identity_rate_limit_per_minute');
        if (! is_int($perMinute) || $perMinute < 1) {
            return false;
        }
        $key = self::key($organization);
        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            return false;
        }
        RateLimiter::hit($key, 60);

        return true;
    }

    public function retryAfterSeconds(Organization $organization): int
    {
        return RateLimiter::availableIn(self::key($organization));
    }
}
