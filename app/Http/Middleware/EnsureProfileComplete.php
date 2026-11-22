<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProfileComplete
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();
        if ($user && self::estIncomplet($user)) {
            return self::redirection($request);
        }

        return $next($request);
    }

    /**
     * TASK-1880 (demande Cyril, 22/11) — la MEME regle sert desormais a
     * publier une annonce, rejoindre une Boucle et ouvrir une Boucle dont on
     * est membre : une seule definition de « presentation faite ».
     */
    public static function estIncomplet(User $user): bool
    {
        return ! $user->first_name || ! $user->name || ! $user->phone || ! $user->city || ! $user->country_code || ! $user->bio;
    }

    /**
     * Vers le formulaire de profil. L'intention n'est retenue que pour un GET :
     * rejouer un POST (rejoindre une Boucle) en GET apres connexion rendrait 405.
     */
    public static function redirection(Request $request): RedirectResponse
    {
        if ($request->isMethod('GET')) {
            session()->put('url.intended', $request->fullUrl());
        }

        return redirect()->to(organizationScopedUrl('profile.edit', 'organization.profile.edit'))
            ->with('profile_required', true);
    }
}
