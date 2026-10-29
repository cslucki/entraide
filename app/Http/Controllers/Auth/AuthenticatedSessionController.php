<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\InvitationResumption;
use App\Services\Users\LoginAccountResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     *
     * TASK-1728 (A3) — le formulaire GLOBAL propose un selecteur
     * d'Organization facultatif (arbitrage MASTER D2).
     *
     * La liste ne contient que des Organizations PUBLIQUES et ACTIVES : elle
     * est donc la meme pour tout visiteur et ne dit RIEN de personne. Aucune
     * appartenance individuelle n'y apparait — c'est la condition qui rend ce
     * selecteur acceptable sur une page accessible sans etre authentifie.
     *
     * Rien n'est passe sur `/org/{slug}/login` : l'Organization y est deja
     * celle de la route, et un selecteur y serait au mieux inutile, au pire
     * une invitation a la contredire — ce que le serveur refuse de toute facon
     * (voir `LoginRequest::organizationDeLaTentative()`).
     */
    public function create(Request $request, LoginAccountResolver $comptes): View
    {
        if ($request->route('organization') !== null) {
            return view('auth.login', ['organizationsProposables' => collect()]);
        }

        return view('auth.login', [
            'organizationsProposables' => $comptes->organizationsProposables(),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $user = $request->user();

        // An invitation (Loop or Blog) parked before login is consumed here,
        // inside the POST: accepting mutates state and must not happen on a GET
        // navigation. The recipient e-mail is re-checked against the freshly
        // authenticated user, so signing in as somebody else cannot claim the
        // token. Returns null when nothing is pending, leaving normal sign-in
        // untouched.
        if ($redirect = app(InvitationResumption::class)->resume($user)) {
            return $redirect;
        }

        return redirect()->intended($user->getLoginRedirectTarget());
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $organization = currentOrganization();

        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        if ($organization) {
            return redirect()->route('organization.login', ['organization' => $organization->slug]);
        }

        return redirect('/');
    }
}
