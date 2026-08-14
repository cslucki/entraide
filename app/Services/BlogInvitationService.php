<?php

namespace App\Services;

use App\Models\BlogPostInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Acceptance of a Blog article invitation.
 *
 * Extracted from BlogInvitationController for the same reason as the Loop one
 * (TASK-1077): acceptance is reachable from three entry points — an
 * authenticated POST, a login POST and a registration POST — and they must not
 * each grow their own copy of the rules.
 *
 * The controller previously accepted an invitation for *any* authenticated user
 * holding a valid token, with no check on the recipient address and none on the
 * Organization. Both are enforced here.
 */
class BlogInvitationService
{
    /** Session key carrying a token across the login / registration POST. */
    public const SESSION_KEY = 'blog_invitation_token';

    public const RESULT_ACCEPTED = 'accepted';

    public const RESULT_ALREADY_ACCEPTED_BY_SAME_USER = 'already_accepted_by_same_user';

    public const RESULT_EMAIL_MISMATCH = 'email_mismatch';

    public const RESULT_EXPIRED = 'expired';

    public const RESULT_TAKEN_BY_ANOTHER_USER = 'taken_by_another_user';

    public const RESULT_POST_UNAVAILABLE = 'post_unavailable';

    public const RESULT_USER_DEACTIVATED = 'user_deactivated';

    public const RESULT_NOT_FOUND = 'not_found';

    /**
     * Accept an invitation on behalf of an authenticated user.
     *
     * Returns one of the RESULT_* constants. Never throws for an expected
     * refusal — callers render a message rather than catching exceptions.
     *
     * @return array{result: string, invitation: ?BlogPostInvitation}
     */
    public function accept(string $token, User $user): array
    {
        return DB::transaction(function () use ($token, $user) {
            $invitation = BlogPostInvitation::where('token', $token)->lockForUpdate()->first();

            if (! $invitation) {
                return ['result' => self::RESULT_NOT_FOUND, 'invitation' => null];
            }

            if ($user->isDeactivated()) {
                return ['result' => self::RESULT_USER_DEACTIVATED, 'invitation' => $invitation];
            }

            // Strict identity check, re-evaluated here and never inherited from
            // whatever happened before authentication: a token must not be
            // usable by an address other than the one it was issued to. This
            // guard did not exist at all before TASK-1078.
            if (! $this->matchesEmail($invitation, $user->email)) {
                return ['result' => self::RESULT_EMAIL_MISMATCH, 'invitation' => $invitation];
            }

            if ($invitation->status === 'accepted') {
                return $invitation->accepted_by_user_id === $user->id
                    ? ['result' => self::RESULT_ALREADY_ACCEPTED_BY_SAME_USER, 'invitation' => $invitation]
                    : ['result' => self::RESULT_TAKEN_BY_ANOTHER_USER, 'invitation' => $invitation];
            }

            if ($invitation->isExpired()) {
                // A row still flagged pending past its window is only flipped
                // when next touched; do it here rather than leave it lying.
                $invitation->update(['status' => 'expired']);

                return ['result' => self::RESULT_EXPIRED, 'invitation' => $invitation];
            }

            $post = $invitation->blogPost;

            if (! $this->postIsReachable($post, $invitation, $user)) {
                return ['result' => self::RESULT_POST_UNAVAILABLE, 'invitation' => $invitation];
            }

            // Already a co-author: no duplicate row, but the invitation must
            // still be closed instead of lingering as pending for ever.
            if (! $post->coAuthors()->where('user_id', $user->id)->exists()) {
                $post->coAuthors()->attach($user->id, [
                    'role' => 'coauthor',
                    'added_by' => $invitation->sender_id,
                ]);
            }

            $invitation->update([
                'status' => 'accepted',
                'accepted_at' => now(),
                'accepted_by_user_id' => $user->id,
            ]);

            return ['result' => self::RESULT_ACCEPTED, 'invitation' => $invitation->fresh()];
        });
    }

    /**
     * Consume a token parked in the session during a login or registration POST.
     *
     * Called from the POST handlers, never from a GET: accepting an invitation
     * mutates state and must not ride on a navigation.
     *
     * @return array{result: string, invitation: ?BlogPostInvitation}|null null
     *                                                                     when nothing was pending
     */
    public function resumeFromSession(User $user): ?array
    {
        $token = session()->pull(self::SESSION_KEY);

        if (! is_string($token) || $token === '') {
            return null;
        }

        return $this->accept($token, $user);
    }

    /**
     * Compared with both sides normalised rather than normalising on write:
     * existing rows are left exactly as they are, and the check still cannot be
     * defeated by casing or stray whitespace.
     */
    public function matchesEmail(BlogPostInvitation $invitation, ?string $email): bool
    {
        $recipient = $this->normalizeEmail($invitation->recipient_email);

        return $recipient !== '' && $recipient === $this->normalizeEmail($email);
    }

    public function normalizeEmail(?string $email): string
    {
        return mb_strtolower(trim((string) $email));
    }

    /** The article must still exist and belong to the invitation's tenant. */
    private function postIsReachable($post, BlogPostInvitation $invitation, User $user): bool
    {
        if (! $post) {
            return false;
        }

        if ($post->organization_id !== $invitation->organization_id) {
            return false;
        }

        // Tenant strictness: accepting must never cross organizations.
        return $user->organization_id === $invitation->organization_id;
    }
}
