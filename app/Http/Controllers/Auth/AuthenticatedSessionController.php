<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
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

        if ($user->is_admin) {
            if ($user->organization_id) {
                $organization = $user->organization;
                if ($organization && $organization->is_active) {
                    return redirect()->intended(canonicalHome($organization));
                }
            }

            return redirect()->intended(route('admin.dashboard', absolute: false));
        }

        if ($user->organization_id) {
            $organization = $user->organization;
            if ($organization && $organization->is_active) {
                return redirect()->intended(canonicalHome($organization));
            }
        }

        return redirect('/');
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
