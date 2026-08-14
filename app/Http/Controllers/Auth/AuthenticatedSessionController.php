<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\InvitationResumption;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
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
