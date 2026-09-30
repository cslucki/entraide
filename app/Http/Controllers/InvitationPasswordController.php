<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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

        return redirect()->intended($user->getLoginRedirectTarget())
            ->with('success', __('organization_invitations.password_set'));
    }
}
