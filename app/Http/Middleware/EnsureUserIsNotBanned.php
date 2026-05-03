<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsNotBanned
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() && Auth::user()->banned_at !== null) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->with('error', 'Votre compte a été suspendu. Contactez l\'administrateur.');
        }

        return $next($request);
    }
}
