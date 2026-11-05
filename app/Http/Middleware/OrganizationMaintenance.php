<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TASK-1749 (demande Cyril, 04/11) — le « Mode maintenance » devient REEL.
 *
 * ## Le defaut ferme, et il etait silencieux
 *
 * `organizations.maintenance_mode` existait depuis longtemps : ecrite par
 * `/admin/organizations/{slug}/edit`, affichee en badge orange dans la liste
 * des Organizations. Elle n'etait **lue par rien** — aucun middleware, aucun
 * controleur, aucune garde, aucun test. Mesure du 04/11 sur tout `app/`,
 * `routes/` et `tests/`.
 *
 * La case promettait pourtant : « Quand active, seuls les admins peuvent
 * acceder a la plateforme de cette organisation. » Cocher cette case ne
 * bloquait personne, et un administrateur qui croyait avoir ferme son
 * Organization la laissait grande ouverte.
 *
 * Meme famille que la case « Je suis disponible » de TASK-1694 : un reglage qui
 * ressemble a un reglage et n'en est pas.
 *
 * ## Le SuperAdmin passe, et c'est la SEULE exemption
 *
 * `is_admin` est le SuperAdmin PLATEFORME, pas l'administrateur d'une
 * Organization — c'est deja la seule exemption que connait
 * `User::canAccessOrganization()`, et on ne lui en invente pas une seconde.
 *
 * ## Ce que ce middleware ne garde PAS, et pourquoi
 *
 * `/org/{slug}/login` vit dans un AUTRE groupe de routes (celui des invites,
 * avec `RedirectIfAuthenticated`). Il reste donc joignable pendant la
 * maintenance, et c'est necessaire : sans cela, un SuperAdmin non connecte ne
 * pourrait pas s'authentifier pour aller voir ce qui se passe. Un membre
 * ordinaire peut s'y connecter aussi — il rencontrera cette page a la premiere
 * surface qu'il demandera, ce qui dit la meme chose.
 *
 * ## 503, et pas 403
 *
 * Une maintenance est TEMPORAIRE. `503` le dit aux robots d'indexation, qui
 * conservent alors la page au lieu de la desindexer ; `403` leur ferait croire
 * a un refus durable. `Retry-After` n'est pas pose : on ne connait pas la duree,
 * et l'inventer serait une promesse.
 */
class OrganizationMaintenance
{
    public function handle(Request $request, Closure $next): Response
    {
        $organization = $this->organizationDeLaRequete($request);

        if ($organization === null || ! $organization->maintenance_mode) {
            return $next($request);
        }

        if ($request->user()?->is_admin) {
            return $next($request);
        }

        // Le NOM seulement si l'Organization est publique — meme discipline que
        // `errors/organization-member-required` (T1483) : nommer une
        // Organization privee a qui n'en fait pas partie confirmerait son
        // existence. Le titre devient neutre sinon.
        return response()
            ->view('errors.organization-maintenance', [
                'organizationName' => $organization->is_public ? $organization->name : null,
            ], 503);
    }

    /**
     * L'Organization de la requete.
     *
     * `app('current_organization')` d'abord : `ResolveOrganization` tourne AVANT
     * ce middleware dans le groupe et l'y a deja posee, resolue. Le repli sur le
     * parametre de route couvre le cas ou l'ordre changerait — une garde qui
     * depend silencieusement de l'ordre de ses voisines finit par ne plus
     * garder.
     */
    private function organizationDeLaRequete(Request $request): ?Organization
    {
        if (app()->bound('current_organization')) {
            $courante = app('current_organization');

            if ($courante instanceof Organization) {
                return $courante;
            }
        }

        $parametre = $request->route('organization') ?? $request->route('community');

        if ($parametre instanceof Organization) {
            return $parametre;
        }

        return $parametre ? Organization::findBySlug((string) $parametre) : null;
    }
}
