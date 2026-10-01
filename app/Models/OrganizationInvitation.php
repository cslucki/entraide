<?php

namespace App\Models;

use Database\Factories\OrganizationInvitationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A named invitation to join one Organization directly (TASK-1659) —
 * modelled on LoopInvitation, with two deliberate differences: there is no
 * `invitation_type` (no in-app notification pipeline exists for this flow),
 * and accepting it CREATES the account rather than requiring one to already
 * exist. `HasOrganizationId` is deliberately NOT used: `organization_id` here
 * names the TARGET Organization of an invitation, not the tenant that owns
 * this row — the two coincide, but the distinction matters for a future
 * reader.
 */
class OrganizationInvitation extends Model
{
    /** @use HasFactory<OrganizationInvitationFactory> */
    use HasFactory, HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    public const LOCALES = ['fr', 'en'];

    public const DEFAULT_LOCALE = 'fr';

    /**
     * Les seuls environnements ou un « Host de test » existe.
     *
     * La liste vit ICI et pas dans un controleur : le formulaire s'en sert
     * pour masquer le champ, la validation pour refuser une soumission
     * malgre le masquage, et le mailer pour ignorer un override deja en base
     * — trois endroits qui doivent dire la meme chose.
     */
    public const HOST_OVERRIDE_ENVIRONMENTS = ['local', 'testing'];

    public static function hostOverrideAllowed(): bool
    {
        return app()->environment(self::HOST_OVERRIDE_ENVIRONMENTS);
    }

    /**
     * Normalise un « Host de test », ou rend `null` si ce n'en est pas un.
     *
     * N'accepte qu'un ORIGIN : schema + hote + port eventuel. Tout le reste
     * est refuse plutot que rogne — accepter en nettoyant ferait croire que
     * l'entree etait bonne, et masquerait une faute de saisie qui enverrait
     * de vrais courriels vers une mauvaise adresse.
     *
     * Refus explicites :
     * - identifiants dans l'URL (`https://user:pass@host`) : ils partiraient
     *   dans chaque courriel ;
     * - query ou fragment : un lien d'invitation porte deja son jeton, et
     *   coller un `?a=b` devant produirait une URL cassee ;
     * - path applicatif : le chemin appartient a BouclePro, jamais au host ;
     * - `http://` ailleurs que sur la machine locale : un jeton d'invitation
     *   ne voyage pas en clair.
     */
    public static function normalizeHostOverride(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        // L'antislash AVANT `parse_url` : PHP le range dans l'hote, alors
        // qu'un navigateur le lit comme « / ». `https://tunnel.test\\collect`
        // passait donc la garde « origin seulement » puis devenait un chemin
        // a l'ouverture (revue 1, 01/10).
        if (str_contains($value, '\\')) {
            return null;
        }

        $parts = parse_url($value);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = mb_strtolower($parts['scheme']);
        $host = mb_strtolower($parts['host']);

        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        // Un path vide ou « / » est le seul tolere : c'est l'origin nu.
        if (isset($parts['path']) && trim($parts['path'], '/') !== '') {
            return null;
        }

        $surMachineLocale = in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true);

        if ($scheme === 'http' && ! $surMachineLocale) {
            return null;
        }

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        if (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535)) {
            return null;
        }

        $origin = $scheme.'://'.$host;

        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        // Slash final normalise : l'URL finale est origin + path, et le path
        // genere par Laravel commence deja par « / ».
        return rtrim($origin, '/');
    }

    protected $fillable = [
        'organization_id',
        'loop_id',
        'created_by_user_id',
        'recipient_first_name',
        'recipient_name',
        'recipient_email',
        'locale',
        'host_override',
        'token',
        'status',
        'expires_at',
        'accepted_at',
        'accepted_by_user_id',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (OrganizationInvitation $invitation) {
            if (empty($invitation->token)) {
                $invitation->token = Str::random(64);
            }

            if (! in_array($invitation->locale, self::LOCALES, true)) {
                $invitation->locale = self::DEFAULT_LOCALE;
            }

            if (is_null($invitation->expires_at)) {
                // 48h, deliberately shorter than loop_invitations' 30 days:
                // this link creates a real account directly on click, so a
                // narrower window limits how long an unclaimed access stays
                // valid (Cyril, 30/09).
                $invitation->expires_at = now()->addHours(48);
            }
        });
    }

    /**
     * Single normalisation entry point, used both when storing an address and
     * when comparing one — the two must never diverge.
     */
    public static function normalizeEmail(?string $email): string
    {
        return mb_strtolower(trim((string) $email));
    }

    public function setRecipientEmailAttribute(?string $value): void
    {
        $this->attributes['recipient_email'] = self::normalizeEmail($value);
    }

    public function recipientFullName(): string
    {
        return trim(($this->recipient_first_name ?? '').' '.($this->recipient_name ?? ''))
            ?: $this->recipient_email;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Boucle cible, optionnelle : ou la personne atterrit, et ce qu'elle rejoint. */
    public function loop(): BelongsTo
    {
        return $this->belongsTo(Loop::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isRevoked(): bool
    {
        return $this->status === self::STATUS_REVOKED;
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    /** Pending *and* still within its validity window. */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING && ! $this->isExpired();
    }

    /** True when this address is the one the invitation was issued to. */
    public function matchesEmail(?string $email): bool
    {
        return $this->recipient_email !== ''
            && $this->recipient_email === self::normalizeEmail($email);
    }

    /** @param  Builder<self>  $query */
    public function scopeValid($query)
    {
        return $query->where('status', self::STATUS_PENDING)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }
}
