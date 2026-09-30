<?php

namespace App\Http\Controllers;

use App\Models\OrganizationInvitation;
use App\Services\OrganizationInvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Public landing and acceptance for TASK-1659 Organization invitations.
 *
 * GET is read-only throughout, same discipline as LoopInvitationController:
 * it renders what the invitation is and offers one button, but never
 * mutates anything. Accepting always goes through the POST, which is also
 * where the account is created and the session is authenticated — there is
 * no intermediate registration form (MASTER, 30/09 16h50).
 */
class OrganizationInvitationController extends Controller
{
    public function __construct(private OrganizationInvitationService $invitations) {}

    public function show(Request $request, string $token): View
    {
        $invitation = OrganizationInvitation::where('token', $token)
            ->with(['organization', 'createdBy'])
            ->firstOrFail();

        return view('organization-invitations.show', [
            'invitation' => $invitation,
            'organization' => $invitation->organization,
            'sender' => $invitation->createdBy,
            'isExpired' => $invitation->isExpired(),
            'isRevoked' => $invitation->isRevoked(),
            'isAccepted' => $invitation->isAccepted(),
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $outcome = $this->invitations->accept($token);

        // Seule une PREMIERE acceptation ouvre une session. Un jeton deja
        // consomme n'authentifie plus personne (defaut corrige le 30/09) :
        // sinon le lien du courriel serait un mot de passe permanent.
        if ($outcome['result'] === OrganizationInvitationService::RESULT_ACCEPTED) {
            $user = $outcome['user'];
            $organization = $outcome['invitation']?->organization;

            Auth::login($user);

            // Second step before anything else: the account was born with a
            // random secret nobody knows (Cyril, 30/09). The middleware
            // enforces it too — this redirect just avoids a pointless bounce
            // through the dashboard route.
            if ($user->must_set_password) {
                return redirect()->route('invitation.password.create')
                    ->with('success', __('organization_invitations.welcome', ['organization' => $organization?->name ?? '']));
            }

            return redirect()->intended($user->getLoginRedirectTarget())
                ->with('success', __('organization_invitations.welcome', ['organization' => $organization?->name ?? '']));
        }

        $message = match ($outcome['result']) {
            OrganizationInvitationService::RESULT_ALREADY_ACCEPTED => __('organization_invitations.flash_already_accepted'),
            OrganizationInvitationService::RESULT_EXPIRED => __('organization_invitations.flash_expired'),
            OrganizationInvitationService::RESULT_REVOKED => __('organization_invitations.flash_revoked'),
            OrganizationInvitationService::RESULT_SANDBOX_FORBIDDEN => __('organization_invitations.flash_sandbox_forbidden'),
            OrganizationInvitationService::RESULT_EMAIL_USED_ELSEWHERE => __('organization_invitations.flash_email_used_elsewhere'),
            default => __('organization_invitations.flash_invalid'),
        };

        return redirect()->route('organization-invitations.show', $token)->with('error', $message);
    }
}
