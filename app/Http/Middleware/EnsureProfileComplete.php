<?php

namespace App\Http\Middleware;

use Closure;
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
        if ($user && (! $user->first_name || ! $user->name || ! $user->phone || ! $user->city || ! $user->country_code || ! $user->bio)) {
            session()->put('url.intended', $request->fullUrl());

            // TASK-1871 — rester dans l'Organization d'ou l'on vient.
            //
            // Cette redirection partait sur la route NUE, alors meme qu'elle
            // range l'URL d'origine dans `url.intended` juste au-dessus : on
            // se souvenait d'ou venait la personne pour l'y ramener apres,
            // mais on la sortait de son Organization pendant.
            //
            // `organizationScopedUrl()` ne DEVINE aucun slug : il ne scope que
            // si l'URL courante est reellement `/org/{slug}/...` ET que ce
            // slug est celui de l'Organization courante. Partout ailleurs —
            // route globale, slug absent, slug etranger — il rend la route
            // nue, et le comportement d'avant cette TASK est conserve.
            //
            // C'est une URL, pas une permission : l'ecran de profil garde ses
            // propres gardes, et l'Organization du compte reste la seule
            // autorite (T1689).
            return redirect()->to(organizationScopedUrl('profile.edit', 'organization.profile.edit'))
                ->with('profile_required', true);
        }

        return $next($request);
    }
}
