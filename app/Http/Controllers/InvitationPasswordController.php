<?php

namespace App\Http\Controllers;

use App\Models\LoopMember;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

/**
 * TASK-1659 — deuxieme etape du parcours d'invitation : la personne vient
 * d'etre connectee automatiquement, mais son compte porte un secret
 * aleatoire que personne ne connait. Elle pose le sien ici, et seulement
 * ensuite elle atteint son Organization.
 *
 * Aucune verification de l'ancien mot de passe : il n'y en a pas a
 * connaitre. C'est l'invitation — un jeton a usage unique envoye a cette
 * adresse precise, deja consomme — qui a prouve l'identite, et le drapeau
 * `must_set_password` qui borne cet ecran au seul cas ou il a un sens.
 */
class InvitationPasswordController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if (! $request->user()->must_set_password) {
            return redirect()->intended($request->user()->getLoginRedirectTarget());
        }

        return view('auth.invitation-password', [
            'organization' => $request->user()->organization,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->must_set_password) {
            return redirect()->intended($user->getLoginRedirectTarget());
        }

        $request->validate([
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user->forceFill([
            'password' => Hash::make($request->input('password')),
            'must_set_password' => false,
        ])->save();

        return redirect($this->destinationFor($user))
            ->with('success', __('organization_invitations.password_set'));
    }

    /**
     * Ou la personne atterrit une fois son mot de passe pose : la Boucle
     * cible de son invitation quand il y en avait une, son Organization
     * sinon.
     *
     * Relu en BASE plutot que garde en session : fermer l'onglet entre
     * l'acceptation et cet ecran ne doit pas faire perdre la destination.
     */
    private function destinationFor(User $user): string
    {
        $invitation = OrganizationInvitation::where('accepted_by_user_id', $user->id)
            ->whereNotNull('loop_id')
            ->latest('accepted_at')
            ->with('loop.organization')
            ->first();

        $loop = $invitation?->loop;

        // La Boucle a pu etre archivee, ou l'adhesion refusee : on ne
        // renvoie jamais vers un ecran qui repondrait 403/404.
        $estMembre = $loop && LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();

        if ($loop && $estMembre && $loop->organization && Route::has('organization.loops.show')) {
            return route('organization.loops.show', [
                'organization' => $loop->organization->slug,
                'loop' => $loop,
            ]);
        }

        return $user->getLoginRedirectTarget();
    }
}
