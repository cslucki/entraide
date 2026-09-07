<?php

namespace App\Services\GuestShell;

use App\Models\GuestVisitor;
use App\Models\Organization;
use App\Models\OrganizationGuestShellPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * TASK-1433 — SW-3 : resout le visiteur pseudonyme d'une Organization depuis
 * un cookie first-party opaque.
 *
 * - le cookie `bp_guest` porte une cle aleatoire (64 caracteres) ; la base ne
 *   garde que son sha256 ; le cookie est chiffre par Laravel (EncryptCookies),
 *   HttpOnly, SameSite=Lax, duree = retention maximale ;
 * - `find()` ne cree RIEN : consulter une page publique n'ecrit pas ;
 * - `ensure()` cree la ligne (et pose le cookie s'il manque) au PREMIER geste
 *   du visiteur (SW-8 : premier message), puis rafraichit `last_seen_at` et
 *   `expires_at` selon la retention de la politique de l'Organization ;
 * - deux Organizations = deux lignes pour la meme cle ; jamais d'IP.
 */
final class GuestVisitorResolver
{
    public const COOKIE = 'bp_guest';

    public const KEY_LENGTH = 64;

    public const COOKIE_MINUTES = 60 * 24 * OrganizationGuestShellPolicy::RETENTION_DAYS_LIMIT;

    /** La cle posee pendant CE cycle de requete (le cookie n'existe pas encore cote client). */
    private ?string $issuedKey = null;

    public function __construct(private readonly GuestShellPolicyService $policies) {}

    public function find(Request $request, Organization $organization): ?GuestVisitor
    {
        $key = $this->key($request);
        if ($key === null) {
            return null;
        }

        $visitor = GuestVisitor::forOrganization($organization)->where('visitor_key_hash', self::hash($key))->first();

        return $visitor !== null && ! $visitor->isExpired() ? $visitor : null;
    }

    /**
     * @param  array{locale?: string|null, referrer?: string|null, utm_source?: string|null, utm_medium?: string|null, utm_campaign?: string|null, shortcut?: string|null, acquisition_journey_id?: string|null}  $attributes
     */
    public function ensure(Request $request, Organization $organization, array $attributes = []): GuestVisitor
    {
        $key = $this->key($request);
        if ($key === null) {
            $key = $this->issue();
        }

        $now = now();
        $retentionDays = $this->policies->policyFor($organization)->retention_days;
        $hash = self::hash($key);

        $visitor = GuestVisitor::forOrganization($organization)->where('visitor_key_hash', $hash)->first();

        if ($visitor !== null && $visitor->isExpired()) {
            // Une ligne expiree n'est pas ressuscitee : la retention est une promesse.
            $visitor->delete();
            $visitor = null;
        }

        if ($visitor === null) {
            return GuestVisitor::create([
                'organization_id' => $organization->getKey(),
                'visitor_key_hash' => $hash,
                'locale' => $this->clean($attributes['locale'] ?? null, 5),
                'referrer' => $this->clean($attributes['referrer'] ?? null, 500),
                'utm_source' => $this->clean($attributes['utm_source'] ?? null, 100),
                'utm_medium' => $this->clean($attributes['utm_medium'] ?? null, 100),
                'utm_campaign' => $this->clean($attributes['utm_campaign'] ?? null, 100),
                'shortcut' => $this->clean($attributes['shortcut'] ?? null, 100),
                // TASK-1447 : la Journey figee a la creation, jamais reecrite ensuite (first touch wins).
                'acquisition_journey_id' => $attributes['acquisition_journey_id'] ?? null,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'expires_at' => $now->copy()->addDays($retentionDays),
            ]);
        }

        $visitor->forceFill([
            'last_seen_at' => $now,
            'expires_at' => $now->copy()->addDays($retentionDays),
            'locale' => $this->clean($attributes['locale'] ?? null, 5) ?? $visitor->locale,
        ])->save();

        return $visitor;
    }

    /** Ce que le visiteur a DECLARE (prenom, role, interet) — jamais extrait automatiquement. */
    public function declare(GuestVisitor $visitor, ?string $firstName, ?string $role, ?string $interest): GuestVisitor
    {
        $visitor->forceFill([
            'declared_first_name' => $this->clean($firstName, 100) ?? $visitor->declared_first_name,
            'declared_role' => $this->clean($role, 100) ?? $visitor->declared_role,
            'declared_interest' => $this->clean($interest, 255) ?? $visitor->declared_interest,
        ])->save();

        return $visitor;
    }

    /** Pose un cookie NEUF (rotation) : apres un claim, l'ancienne cle ne resout plus rien. */
    public function rotate(): string
    {
        return $this->issue();
    }

    public static function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    private function key(Request $request): ?string
    {
        if ($this->issuedKey !== null) {
            return $this->issuedKey;
        }

        $raw = $request->cookie(self::COOKIE);
        if (! is_string($raw) || strlen($raw) !== self::KEY_LENGTH || ! ctype_alnum($raw)) {
            return null;
        }

        return $raw;
    }

    private function issue(): string
    {
        $key = Str::random(self::KEY_LENGTH);
        $this->issuedKey = $key;

        Cookie::queue(Cookie::make(
            self::COOKIE,
            $key,
            self::COOKIE_MINUTES,
            path: '/',
            domain: null,
            secure: (bool) config('session.secure', false),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        ));

        return $key;
    }

    private function clean(?string $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $max, '');
    }
}
