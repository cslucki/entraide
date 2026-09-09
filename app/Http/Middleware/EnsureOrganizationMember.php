<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TASK-1479 (P0 privacy) — la frontiere d'acces d'une surface INTERNE
 * d'Organization.
 *
 * ## Ce qui a ete mesure, et qui a rendu ce fichier necessaire
 *
 * `/org/{slug}/membres` et `/org/{slug}/explorer` etaient servis en HTTP 200 a
 * un visiteur **totalement anonyme**, sur des Organizations `is_public = false`
 * comprises. Leur chaine de middlewares complete etait
 * `web | ResolveOrganization` : ni authentification, ni appartenance, ni
 * verification de publicite.
 *
 * Ce que la page rendait a ce visiteur : des noms reels, des villes, des
 * biographies, des affiliations professionnelles. La forme non prefixee
 * `/membres` faisait de meme sur l'Organization par defaut — 42 personnes.
 *
 * ## Ou etait le defaut, precisement
 *
 * PAS dans la requete SQL. `HomeController@members` filtre correctement sur
 * `organization_id` : elle est bornee au tenant VISITE. Le defaut est qu'elle
 * sert le tenant que l'URL designe **a qui le demande**, sans jamais demander
 * qui le demande.
 *
 * Le correctif porte donc sur QUI peut atteindre le controleur, et nulle part
 * ailleurs. Aucune donnee n'a change de visibilite ; aucun champ n'est masque ;
 * aucune requete n'est reecrite.
 *
 * ## Pourquoi un middleware plutot qu'un `if` dans le controleur
 *
 * Parce que la regle n'appartient pas a une page. Elle existait deja, mais
 * enfermee dans une methode privee — `LoopController::assertUserBelongsToOrganization()`
 * — donc inaccessible a toute autre surface. Une copie de plus dans
 * `HomeController` aurait fait une troisieme ecriture de la meme phrase.
 *
 * ## L'acces transverse du SuperAdmin n'est pas invente ici
 *
 * `is_admin` est **exactement** le predicat que `OrgAdminMiddleware` utilise
 * deja pour accorder un acces transverse. On le reprend tel quel : ouvrir un
 * nouveau contournement a l'occasion d'un correctif de fuite serait le
 * contraire du but.
 *
 * ## 404 et non 403
 *
 * Meme choix que `assertUserBelongsToOrganization()`, et pour la meme raison :
 * un 403 confirmerait a un tiers que cette Organization existe.
 */
final class EnsureOrganizationMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Ceinture. `auth` doit avoir agi avant — mais cette garde ne repose
        // pas sur l'ordre de declaration d'une route.
        if ($user === null) {
            abort(404);
        }

        $organization = currentOrganization();

        // Aucun tenant resolu : la requete n'a pas atteint une surface
        // d'Organization. `ResolveUrlOrganization` rend deja `setup-required`
        // dans ce cas ; on ne s'y substitue pas.
        if (! $organization instanceof Organization) {
            return $next($request);
        }

        if ($user->organization_id === $organization->id) {
            return $next($request);
        }

        if ($user->is_admin) {
            return $next($request);
        }

        abort(404);
    }
}
