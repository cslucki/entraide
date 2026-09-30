<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\PointLedger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Direct-to-Organization invitations (TASK-1659): a SuperAdmin names a
 * person, an e-mail goes out, and clicking it creates the account — no
 * registration form, no password ever transmitted.
 *
 * Every state transition lives here rather than in a controller, exactly as
 * LoopInvitationService does for Loops, so the admin surface (create/resend)
 * and the public accept path cannot drift apart on the same rules.
 */
class OrganizationInvitationService
{
    /** Outcome of invite(): which case applied. */
    public const CASE_CREATED = 'created';

    public const CASE_RESENT = 'resent';

    public const CASE_ALREADY_MEMBER = 'already_member';

    public const CASE_EMAIL_USED_ELSEWHERE = 'email_used_elsewhere';

    public const CASE_SANDBOX_FORBIDDEN = 'sandbox_forbidden';

    /** Outcome of accept(). */
    public const RESULT_ACCEPTED = 'accepted';

    public const RESULT_ALREADY_ACCEPTED = 'already_accepted';

    public const RESULT_EXPIRED = 'expired';

    public const RESULT_REVOKED = 'revoked';

    public const RESULT_SANDBOX_FORBIDDEN = 'sandbox_forbidden';

    public const RESULT_EMAIL_USED_ELSEWHERE = 'email_used_elsewhere';

    public const RESULT_NOT_FOUND = 'not_found';

    /**
     * Create — or refresh — the single pending invitation for this
     * recipient, in this Organization.
     *
     * The three business cases (§16-18 of the MASTER brief) are all decided
     * HERE, under one transaction with lockForUpdate, so a SuperAdmin
     * submitting several people in one operation and a resend from the
     * tracking table share exactly the same rules and cannot race each
     * other into two live tokens for the same address.
     *
     * @return array{case: string, invitation: ?OrganizationInvitation, user: ?User}
     */
    public function invite(Organization $organization, User $admin, string $email, ?string $firstName, ?string $lastName, ?string $locale = null): array
    {
        $email = OrganizationInvitation::normalizeEmail($email);
        $locale = in_array($locale, OrganizationInvitation::LOCALES, true)
            ? $locale
            : OrganizationInvitation::DEFAULT_LOCALE;

        return DB::transaction(function () use ($organization, $admin, $email, $firstName, $lastName, $locale) {
            // TASK-1650, third expression of the same guard: no real account
            // is ever provisioned into a Scenario Manager sandbox. Checked
            // here, not only at the form's server-side validation, because
            // resend() re-enters this same method without re-validating the
            // request.
            if ($organization->scenario_sandbox_created_at !== null) {
                return ['case' => self::CASE_SANDBOX_FORBIDDEN, 'invitation' => null, 'user' => null];
            }

            $existingUser = User::where('email', $email)->lockForUpdate()->first();

            if ($existingUser) {
                if ($existingUser->organization_id === $organization->id) {
                    return ['case' => self::CASE_ALREADY_MEMBER, 'invitation' => null, 'user' => $existingUser];
                }

                // Cas C — the schema makes a second User for this e-mail
                // impossible (users.email is a GLOBAL unique index), and
                // moving the existing account would silently relocate its
                // content without it (the exact defect measured ahead of
                // TASK-1639). So: no invitation, no e-mail, no mutation.
                return ['case' => self::CASE_EMAIL_USED_ELSEWHERE, 'invitation' => null, 'user' => $existingUser];
            }

            $existing = OrganizationInvitation::where('organization_id', $organization->id)
                ->where('recipient_email', $email)
                ->lockForUpdate()
                ->get();

            $pending = $existing->first(fn (OrganizationInvitation $i) => $i->isPending());

            if ($pending) {
                // Refresh the human-facing fields and PUSH the expiry back
                // out to a fresh 48h window (Cyril, 30/09) — "Relancer"
                // must extend the deadline, not just resend the same link
                // with its original clock still running. The token itself
                // is kept: the link already in the recipient's mailbox
                // stays valid, it just lives longer.
                $pending->update(array_merge(
                    array_filter([
                        'recipient_first_name' => $firstName,
                        'recipient_name' => $lastName,
                    ], fn ($v) => $v !== null),
                    ['locale' => $locale, 'expires_at' => now()->addHours(48)],
                ));

                return ['case' => self::CASE_RESENT, 'invitation' => $pending->fresh(), 'user' => null];
            }

            // A stale row flagged pending but past its window would
            // otherwise block a fresh invitation for ever.
            $existing->filter(fn (OrganizationInvitation $i) => $i->status === OrganizationInvitation::STATUS_PENDING && $i->isExpired())
                ->each(fn (OrganizationInvitation $i) => $i->update(['status' => OrganizationInvitation::STATUS_EXPIRED]));

            $invitation = OrganizationInvitation::create([
                'organization_id' => $organization->id,
                'created_by_user_id' => $admin->id,
                'recipient_first_name' => $firstName,
                'recipient_name' => $lastName,
                'recipient_email' => $email,
                'locale' => $locale,
                'status' => OrganizationInvitation::STATUS_PENDING,
            ]);

            return ['case' => self::CASE_CREATED, 'invitation' => $invitation, 'user' => null];
        });
    }

    public function revoke(OrganizationInvitation $invitation): void
    {
        if ($invitation->status !== OrganizationInvitation::STATUS_PENDING) {
            // Never retroactively revoke an accepted invitation, and treat
            // an already expired/revoked one as a no-op rather than an
            // error.
            throw new \RuntimeException('Only a pending invitation can be revoked.');
        }

        $invitation->update(['status' => OrganizationInvitation::STATUS_REVOKED]);
    }

    /**
     * Accept an invitation by token — public, unauthenticated. Creates the
     * User (minimal fields only, random unusable password) and verifies its
     * e-mail, because clicking a token sent to that exact address IS the
     * proof of possession. Never throws for an expected refusal: callers
     * render a message, they do not catch exceptions.
     *
     * @return array{result: string, invitation: ?OrganizationInvitation, user: ?User}
     */
    public function accept(string $token): array
    {
        return DB::transaction(function () use ($token) {
            $invitation = OrganizationInvitation::where('token', $token)->lockForUpdate()->first();

            if (! $invitation) {
                return ['result' => self::RESULT_NOT_FOUND, 'invitation' => null, 'user' => null];
            }

            if ($invitation->isRevoked()) {
                return ['result' => self::RESULT_REVOKED, 'invitation' => $invitation, 'user' => null];
            }

            if ($invitation->isAccepted()) {
                // Same person clicking their own link again: send them back
                // in rather than erroring.
                return [
                    'result' => self::RESULT_ALREADY_ACCEPTED,
                    'invitation' => $invitation,
                    'user' => $invitation->acceptedBy,
                ];
            }

            if ($invitation->isExpired()) {
                $invitation->update(['status' => OrganizationInvitation::STATUS_EXPIRED]);

                return ['result' => self::RESULT_EXPIRED, 'invitation' => $invitation, 'user' => null];
            }

            $organization = $invitation->organization;

            // Defensive re-check: the Organization could have become a
            // sandbox, or been deactivated, after the invitation was sent.
            if (! $organization || $organization->scenario_sandbox_created_at !== null) {
                return ['result' => self::RESULT_SANDBOX_FORBIDDEN, 'invitation' => $invitation, 'user' => null];
            }

            $existingUser = User::where('email', $invitation->recipient_email)->lockForUpdate()->first();

            if ($existingUser) {
                if ($existingUser->organization_id !== $organization->id) {
                    // The address was claimed elsewhere between send and
                    // click (e.g. a manual admin action). Never move it.
                    return ['result' => self::RESULT_EMAIL_USED_ELSEWHERE, 'invitation' => $invitation, 'user' => null];
                }

                // Already a member by the time the link was clicked: honour
                // the invitation without creating a second account.
                $invitation->update([
                    'status' => OrganizationInvitation::STATUS_ACCEPTED,
                    'accepted_at' => now(),
                    'accepted_by_user_id' => $existingUser->id,
                ]);

                return ['result' => self::RESULT_ACCEPTED, 'invitation' => $invitation->fresh(), 'user' => $existingUser];
            }

            $user = User::create([
                'name' => $invitation->recipientFullName(),
                'first_name' => $invitation->recipient_first_name,
                'email' => $invitation->recipient_email,
                // Random, hashed, and never transmitted anywhere — the
                // person sets their own password later through the
                // existing "forgot password" primitive if they want one.
                'password' => Hash::make(bin2hex(random_bytes(16))),
                'points_balance' => 100,
                'organization_id' => $organization->id,
            ]);

            // Both flags are deliberately NOT in User::$fillable —
            // mass-assigning them would let any other form flip them by
            // accident. This is the one place allowed to set them.
            //
            // email_verified_at: clicking a token sent to this exact
            // address IS the proof of possession (MASTER,
            // INVITATION_CLICK_VERIFIES_EMAIL = YES).
            //
            // must_set_password: the account was born with a random secret
            // nobody knows, so the person must choose one before going
            // anywhere (Cyril, 30/09) — otherwise they never would.
            $user->forceFill([
                'email_verified_at' => now(),
                'must_set_password' => true,
            ])->save();

            PointLedger::create([
                'user_id' => $user->id,
                'transaction_id' => null,
                'delta' => 100,
                'organization_id' => $user->organization_id,
                'reason' => 'welcome_bonus',
            ]);

            $invitation->update([
                'status' => OrganizationInvitation::STATUS_ACCEPTED,
                'accepted_at' => now(),
                'accepted_by_user_id' => $user->id,
            ]);

            return ['result' => self::RESULT_ACCEPTED, 'invitation' => $invitation->fresh(), 'user' => $user];
        });
    }
}
