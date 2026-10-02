<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TASK-1659 — un compte ne au clic d'une invitation porte un secret
 * ALEATOIRE que personne ne connait. Tant que la personne n'a pas pose le
 * sien, elle ne va nulle part ailleurs que sur l'ecran qui le lui demande.
 *
 * La garde est ICI et pas dans une simple redirection apres `Auth::login()` :
 * une redirection se contourne en tapant n'importe quelle autre URL, et la
 * personne se retrouverait avec un compte dont elle ne peut plus jamais se
 * reconnecter sans passer par « mot de passe oublie ». Le drapeau vit en
 * base, donc fermer l'onglet ne fait pas sauter l'etape non plus.
 *
 * Trois exceptions, sans lesquelles l'ecran serait une impasse : l'ecran
 * lui-meme, la deconnexion, et la verification d'email du framework.
 */
class EnsureInvitationPasswordIsSet
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->must_set_password) {
            return $next($request);
        }

        if ($request->routeIs('invitation.password.*', 'logout', 'verification.*')) {
            return $next($request);
        }

        return redirect()->route('invitation.password.create');
    }
}
