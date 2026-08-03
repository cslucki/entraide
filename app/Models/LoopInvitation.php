<?php

namespace App\Models;

use App\Models\Traits\HasOrganizationId;
use Database\Factories\LoopInvitationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A named invitation to join one specific Loop.
 *
 * Modelled on BlogPostInvitation, with two deliberate differences:
 * a `revoked` status, and an e-mail that is normalised on write so the strict
 * `recipient_email === user->email` check at accept time cannot be defeated by
 * casing or stray whitespace.
 */
class LoopInvitation extends Model
{
    /** @use HasFactory<LoopInvitationFactory> */
    use HasFactory, HasOrganizationId, HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    public const TYPE_EXISTING_MEMBER = 'existing_member';

    public const TYPE_EXTERNAL = 'external';

    /** Session key carrying a token across the login / registration POST. */
    public const SESSION_KEY = 'loop_invitation_token';

    protected $fillable = [
        'organization_id',
        'loop_id',
        'sender_id',
        'recipient_email',
        'recipient_name',
        'token',
        'message',
        'invitation_type',
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
        static::creating(function (LoopInvitation $invitation) {
            if (empty($invitation->token)) {
                $invitation->token = Str::random(64);
            }

            if (is_null($invitation->expires_at)) {
                $invitation->expires_at = now()->addDays(30);
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

    public function loop(): BelongsTo
    {
        return $this->belongsTo(Loop::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
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

    /**
     * Single source of truth for who may see which invitations.
     *
     * The Organization invitations page is a *personal* screen — every existing
     * query on it is scoped to the current user — so listing every invitation of
     * the Organization there would expose each member's invitations to all the
     * others. Visibility therefore widens by role instead:
     *
     * - anyone: what they sent themselves;
     * - active Loop owner: everything on the Loops they own;
     * - Organization admin: everything in their Organization.
     *
     * Used by the tracking page, the Members Card and the post-creation screen so
     * the three never drift apart.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo($query, User $user)
    {
        $query->where('organization_id', $user->organization_id);

        $isOrganizationAdmin = $user->organization_id
            && $user->organization?->admin_id === $user->id;

        if ($isOrganizationAdmin) {
            return $query;
        }

        return $query->where(function ($q) use ($user) {
            $q->where('sender_id', $user->id)
                ->orWhereIn('loop_id', LoopMember::query()
                    ->where('user_id', $user->id)
                    ->where('role', 'owner')
                    ->where('status', 'active')
                    ->select('loop_id'));
        });
    }
}
