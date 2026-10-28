<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un compte desactive ne poursuit pas sa session.
 *
 * ## TASK-1716 — le refus est le meme, le message ne l'est pas
 *
 * La garde ferme toujours sur `banned_at` : c'est la colonne qui porte la
 * desactivation EFFECTIVE, et elle est lue ici comme avant. Ce qui change est
 * ce qu'on DIT a la personne.
 *
 * `self_closed_at` distingue la fermeture volontaire du bannissement
 * administratif. Sans lui, quelqu'un qui venait de fermer son compte depuis un
 * autre navigateur voyait « votre compte a ete SUSPENDU, contactez
 * l'administrateur » — un mot de sanction, et une demarche inutile, pour sa
 * propre decision.
 *
 * Ce chemin est d'ailleurs la ou la revocation des acces se CONSTATE : la
 * fermeture deconnecte la session qui l'a demandee, mais une seconde session
 * ouverte ailleurs est fermee ici, a sa requete suivante. Les jetons d'API,
 * eux, sont deja supprimes par `User::booted()` des que `banned_at` passe
 * non-nul.
 *
 * ## Les deux gardes d'authentification, et pourquoi elles sont deux
 *
 * Sur `/api/*`, `Auth::user()` est nul et seul `sanctum` sait qui parle. Ce
 * middleware est declare dans les DEUX groupes (`bootstrap/app.php`), et c'est
 * le modele qu'a suivi `RequireApprovedAccount` en TASK-1714.
 */
class EnsureUserIsNotBanned
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('sanctum')->check()
            ? Auth::guard('sanctum')->user()
            : (Auth::check() ? Auth::user() : null);

        if ($user && $user->banned_at !== null) {
            // TASK-1716 — une seule lecture, deux messages. Le libelle etait
            // ecrit EN DUR en francais ici, alors que la branche JSON juste
            // en dessous passait deja par `trans()` : la page de connexion
            // parlait francais a un compte anglophone.
            // `method_exists` comme dans `RequireApprovedAccount` : `$user`
            // est un `Authenticatable`, pas forcement notre `User`. La garde
            // ne doit jamais etre ce qui casse une requete.
            $message = method_exists($user, 'estFermeVolontairement') && $user->estFermeVolontairement()
                ? trans('auth.closed_by_owner')
                : trans('auth.deactivated');

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 403);
            }

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('error', $message);
        }

        return $next($request);
    }
}
