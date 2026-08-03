<?php

namespace App\Services;

use App\Http\Controllers\BlogInvitationController;
use App\Http\Controllers\LoopInvitationController;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * Single entry point for "an invitation was parked before the visitor
 * authenticated — pick it up now".
 *
 * Both authentication controllers call this once, so the login and the
 * registration paths cannot drift apart, and adding a third kind of invitation
 * later means touching this class rather than every POST handler.
 *
 * Deliberately a thin dispatcher: each flow keeps its own service, its own
 * session key and its own guards. Nothing about the Loop flow (TASK-1077)
 * changes here — same calls, same order, same redirects.
 */
class InvitationResumption
{
    public function __construct(
        private LoopInvitationService $loopInvitations,
        private BlogInvitationService $blogInvitations,
    ) {}

    /**
     * Consume whichever invitation token is parked in the session.
     *
     * Returns null — and leaves the session untouched — when there is nothing
     * pending, so a normal sign-in or registration is unaffected.
     */
    public function resume(User $user): ?RedirectResponse
    {
        if ($outcome = $this->loopInvitations->resumeFromSession($user)) {
            return app(LoopInvitationController::class)->redirectForOutcome($outcome);
        }

        if ($outcome = $this->blogInvitations->resumeFromSession($user)) {
            return app(BlogInvitationController::class)->redirectForOutcome($outcome);
        }

        return null;
    }
}
