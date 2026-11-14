<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TASK-1829 — le mode `explain` d'{@see EnsureOrganizationMember}, porte par
 * une classe INDEPENDANTE (aucun lien d'heritage) plutot que par le
 * parametre `:explain` sur la meme classe.
 *
 * ## Pourquoi une classe, et pas `organization.member:explain` comme avant
 *
 * `Router::resolveMiddleware()` (Laravel 13.42) exclut desormais un
 * middleware parametre des que sa CLASSE sous-jacente figure dans la liste
 * d'exclusion — `explode(':', $name)[0]` — et aussi par heritage
 * (`isSubclassOf`). Le groupe du tableau de bord fait
 * `Route::middleware('organization.member:explain')->withoutMiddleware(['organization.member'])` :
 * sous cette nouvelle regle, l'exclusion de `organization.member` (la
 * classe nue) retirait AUSSI `organization.member:explain`, puisque les
 * deux partagent la meme classe — la garde du tableau de bord disparaissait
 * entierement (mesure en CI, `TASK1732SiblingAccountByInvitationTest`).
 *
 * Une classe distincte, qui ne descend PAS d'{@see EnsureOrganizationMember},
 * echappe aux deux formes d'exclusion. La regle d'appartenance elle-meme ne
 * change pas d'un mot — {@see EnsureOrganizationMember::enforce()} reste
 * l'UNIQUE autorite, appelee ici en mode explicite.
 */
final class EnsureOrganizationMemberExplained
{
    public function handle(Request $request, Closure $next): Response
    {
        return EnsureOrganizationMember::enforce($request, $next, EnsureOrganizationMember::MODE_EXPLAIN);
    }
}
