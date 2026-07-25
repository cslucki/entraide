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
        $user = Auth::guard('sanctum')->check()
            ? Auth::guard('sanctum')->user()
            : (Auth::check() ? Auth::user() : null);

        if ($user && $user->banned_at !== null) {
            if ($request->expectsJson()) {
                return response()->json(['message' => trans('auth.deactivated')], 403);
            }

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->with('error', 'Votre compte a été suspendu. Contactez l\'administrateur.');
        }

        return $next($request);
    }
}
