<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
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
 * ## 404 par defaut, 403 explique sur demande
 *
 * Le refus par defaut est un 404, comme `assertUserBelongsToOrganization()` et
 * pour la meme raison : sur l'annuaire, un 403 confirmerait a un tiers que
 * cette Organization existe.
 *
 * TASK-1483 ajoute un second mode, `organization.member:explain`. Sur le
 * tableau de bord, la personne est DEJA connectee et a tape le slug elle-meme :
 * lui rendre un 404 generique la laisserait croire a une page cassee. Le refus
 * explique donc ce qui se passe.
 *
 * **La regle d'appartenance ne change pas d'un mot** — seule la maniere de
 * refuser change. Ecrire une seconde garde pour cela aurait cree une deuxieme
 * autorite d'appartenance, c'est-a-dire exactement ce que TASK-1479 a evite.
 */
final class EnsureOrganizationMember
{
    /** Le mode de refus qui EXPLIQUE, au lieu de rendre un 404 generique. */
    public const MODE_EXPLAIN = 'explain';

    public function handle(Request $request, Closure $next, ?string $mode = null): Response
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

        if ($mode === self::MODE_EXPLAIN) {
            return $this->explain($organization);
        }

        abort(404);
    }

    /**
     * Le refus qui explique. Il ne montre RIEN du tenant vise, a une exception
     * pres et elle est mesuree : le NOM, uniquement si l'Organization est
     * publique.
     *
     * Pour une Organization privee, nommer la cible reviendrait a confirmer son
     * existence a quelqu'un qui n'en fait pas partie — la meme raison qui fait
     * du 404 le bon refus sur l'annuaire. Le texte devient alors neutre.
     *
     * Le second lien suit la meme discipline : « voir l'accueil de cette
     * organisation » n'est propose que si cet accueil existe reellement.
     * Mesure faite : `/org/artscilab-en` (privee) rend 302, pas une landing.
     */
    private function explain(Organization $organization): Response
    {
        $isPublic = (bool) $organization->is_public;

        return response()->view('errors.organization-member-required', [
            'organizationName' => $isPublic ? $organization->name : null,
            'publicHomeUrl' => $isPublic && Route::has('organization.home')
                ? route('organization.home', ['organization' => $organization->slug])
                : null,
            'ownSpaceUrl' => $this->ownSpaceUrl(),
        ], 403);
    }

    /**
     * « Retourner a mon espace » : le tableau de bord de SON Organization, pas
     * une destination inventee. Si elle n'est pas resoluble, on retombe sur la
     * racine plutot que de fabriquer une URL.
     */
    private function ownSpaceUrl(): string
    {
        $own = auth()->user()?->organization;

        return $own instanceof Organization && Route::has('organization.dashboard')
            ? route('organization.dashboard', ['organization' => $own->slug])
            : url('/');
    }
}
