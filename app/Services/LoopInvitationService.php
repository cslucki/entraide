<?php

namespace App\Services;

use App\Models\Loop;
use App\Models\LoopInvitation;
use App\Models\LoopJoinRequest;
use App\Models\LoopMember;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Targeted e-mail invitations to a single Loop.
 *
 * Every state transition lives here rather than in the controller, because the
 * accept path is reachable from three entry points (an authenticated POST, a
 * login POST and a registration POST) and they must not each grow their own
 * copy of the rules.
 */
class LoopInvitationService
{
    /** Outcome of an accept attempt: why it failed, or that it succeeded. */
    public const RESULT_ACCEPTED = 'accepted';

    public const RESULT_ALREADY_ACCEPTED_BY_SAME_USER = 'already_accepted_by_same_user';

    public const RESULT_EMAIL_MISMATCH = 'email_mismatch';

    public const RESULT_EXPIRED = 'expired';

    public const RESULT_REVOKED = 'revoked';

    public const RESULT_TAKEN_BY_ANOTHER_USER = 'taken_by_another_user';

    public const RESULT_LOOP_UNAVAILABLE = 'loop_unavailable';

    public const RESULT_USER_DEACTIVATED = 'user_deactivated';

    public const RESULT_NOT_FOUND = 'not_found';

    public function __construct(private LoopService $loopService) {}

    /**
     * Create — or reuse — the single pending invitation for this recipient.
     *
     * Two people inviting the same address at the same moment must not produce
     * two live tokens, so the uniqueness check and the insert share one
     * transaction and lock the existing rows for this loop + address.
     */
    public function invite(Loop $loop, User $sender, string $email, ?string $name = null, ?string $message = null): LoopInvitation
    {
        $email = LoopInvitation::normalizeEmail($email);

        return DB::transaction(function () use ($loop, $sender, $email, $name, $message) {
            $existing = LoopInvitation::where('loop_id', $loop->id)
                ->where('recipient_email', $email)
                ->lockForUpdate()
                ->get();

            $pending = $existing->first(fn (LoopInvitation $i) => $i->isPending());

            if ($pending) {
                // Refresh the human-facing fields but keep the token already in
                // the recipient's mailbox valid.
                $pending->update(array_filter([
                    'recipient_name' => $name,
                    'message' => $message,
                ], fn ($v) => $v !== null));

                return $pending->fresh();
            }

            // A stale row flagged pending but past its window would otherwise
            // block a fresh invitation for ever.
            $existing->filter(fn (LoopInvitation $i) => $i->status === LoopInvitation::STATUS_PENDING && $i->isExpired())
                ->each(fn (LoopInvitation $i) => $i->update(['status' => LoopInvitation::STATUS_EXPIRED]));

            return LoopInvitation::create([
                'organization_id' => $loop->organization_id,
                'loop_id' => $loop->id,
                'sender_id' => $sender->id,
                'recipient_email' => $email,
                'recipient_name' => $name,
                'message' => $message,
                'invitation_type' => $this->resolveType($loop, $email),
                'status' => LoopInvitation::STATUS_PENDING,
            ]);
        });
    }

    /**
     * `existing_member` when the address already belongs to an assignable user
     * of this Loop's Organization — the mail then links straight to the landing
     * page instead of offering to create an account.
     */
    public function resolveType(Loop $loop, string $email): string
    {
        $exists = User::assignable()
            ->where('organization_id', $loop->organization_id)
            ->whereRaw('LOWER(email) = ?', [LoopInvitation::normalizeEmail($email)])
            ->exists();

        return $exists ? LoopInvitation::TYPE_EXISTING_MEMBER : LoopInvitation::TYPE_EXTERNAL;
    }

    public function revoke(LoopInvitation $invitation): void
    {
        if ($invitation->status !== LoopInvitation::STATUS_PENDING) {
            // Never retroactively revoke an accepted invitation, and treat an
            // already expired/revoked one as a no-op rather than an error.
            throw new \RuntimeException('Only a pending invitation can be revoked.');
        }

        $invitation->update(['status' => LoopInvitation::STATUS_REVOKED]);
    }

    /**
     * Accept an invitation on behalf of an authenticated user.
     *
     * Returns one of the RESULT_* constants. Never throws for an expected
     * refusal — callers render a message, they do not catch exceptions.
     *
     * @return array{result: string, invitation: ?LoopInvitation}
     */
    public function accept(string $token, User $user): array
    {
        return DB::transaction(function () use ($token, $user) {
            $invitation = LoopInvitation::where('token', $token)->lockForUpdate()->first();

            if (! $invitation) {
                return ['result' => self::RESULT_NOT_FOUND, 'invitation' => null];
            }

            if ($user->isDeactivated()) {
                return ['result' => self::RESULT_USER_DEACTIVATED, 'invitation' => $invitation];
            }

            // Strict identity check, re-evaluated here and not inherited from
            // whatever happened before login: a token must never be usable by
            // an address other than the one it was issued to.
            if (! $invitation->matchesEmail($user->email)) {
                return ['result' => self::RESULT_EMAIL_MISMATCH, 'invitation' => $invitation];
            }

            if ($invitation->isRevoked()) {
                return ['result' => self::RESULT_REVOKED, 'invitation' => $invitation];
            }

            if ($invitation->isAccepted()) {
                // Same person coming back on their own link: send them in.
                return $invitation->accepted_by_user_id === $user->id
                    ? ['result' => self::RESULT_ALREADY_ACCEPTED_BY_SAME_USER, 'invitation' => $invitation]
                    : ['result' => self::RESULT_TAKEN_BY_ANOTHER_USER, 'invitation' => $invitation];
            }

            if ($invitation->isExpired()) {
                $invitation->update(['status' => LoopInvitation::STATUS_EXPIRED]);

                return ['result' => self::RESULT_EXPIRED, 'invitation' => $invitation];
            }

            $loop = $invitation->loop;

            if (! $this->loopIsJoinable($loop, $invitation, $user)) {
                return ['result' => self::RESULT_LOOP_UNAVAILABLE, 'invitation' => $invitation];
            }

            $alreadyMember = LoopMember::where('loop_id', $loop->id)
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->exists();

            if (! $alreadyMember) {
                try {
                    $this->loopService->addMemberByUserId($loop, $user->id);
                } catch (\RuntimeException|ModelNotFoundException) {
                    // addMemberByUserId owns the cross-organization and
                    // deactivation invariants; a refusal there means the
                    // invitation cannot be honoured.
                    return ['result' => self::RESULT_LOOP_UNAVAILABLE, 'invitation' => $invitation];
                }
            }

            // Marked accepted in both branches: an invitation sent to someone
            // who had joined in the meantime must not stay pending for ever.
            $invitation->update([
                'status' => LoopInvitation::STATUS_ACCEPTED,
                'accepted_at' => now(),
                'accepted_by_user_id' => $user->id,
            ]);

            LoopJoinRequest::where('loop_id', $loop->id)
                ->where('user_id', $user->id)
                ->where('status', LoopJoinRequest::STATUS_PENDING)
                ->update([
                    'status' => LoopJoinRequest::STATUS_ACCEPTED,
                    'decided_by' => $invitation->sender_id ?? $user->id,
                    'decided_at' => now(),
                ]);

            return ['result' => self::RESULT_ACCEPTED, 'invitation' => $invitation->fresh()];
        });
    }

    /**
     * Consume a token parked in the session during a login or registration POST.
     *
     * Deliberately called from the POST handlers, never from a GET: accepting an
     * invitation mutates state and must not ride on a navigation.
     *
     * @return array{result: string, invitation: ?LoopInvitation}|null null when
     *                                                                 nothing was pending
     */
    public function resumeFromSession(User $user): ?array
    {
        $token = session()->pull(LoopInvitation::SESSION_KEY);

        if (! is_string($token) || $token === '') {
            return null;
        }

        return $this->accept($token, $user);
    }

    /** The Loop itself must still be joinable, whatever the invitation says. */
    private function loopIsJoinable(?Loop $loop, LoopInvitation $invitation, User $user): bool
    {
        if (! $loop || ! $loop->isActive()) {
            return false;
        }

        if ($loop->organization_id !== $invitation->organization_id) {
            return false;
        }

        // Tenant strictness: the invitee must belong to the Loop's Organization.
        if ($user->organization_id !== $loop->organization_id) {
            return false;
        }

        return (bool) $loop->organization?->loops_enabled;
    }
}
