<?php

namespace App\Support\Errors;

use App\Models\Organization;
use Illuminate\Support\Facades\Route;

/**
 * TASK-1685 — « cette page appartient-elle a l'espace d'une AUTRE Organization ? »
 *
 * ## Pourquoi cette classe existe
 *
 * La question se pose sur DEUX pages d'erreur : le 404 (un refus de tenant
 * pose par un controleur, doctrine T1601-T1606) et le 403 (une policy qui
 * refuse, par exemple `Dossier::viewAny`). Ecrire la decision dans chaque
 * gabarit aurait cree deux autorites pour une meme phrase, qui auraient fini
 * par diverger — exactement ce que ce depot interdit.
 *
 * Elle ne decide RIEN sur l'acces : les gardes ont deja tranche et le statut
 * HTTP est deja choisi quand on arrive ici. Elle repond uniquement a « quelle
 * page faut-il montrer ».
 *
 * ## Les trois predicats, et pourquoi chacun est la
 *
 *  1. **un utilisateur authentifie portant une Organization.** Un invite n'a
 *     pas d'espace a lui ; T1606 a deliberement laisse son cas inchange, et on
 *     ne lui invente rien.
 *
 *  2. **une route DECLAREE a ete resolue.** C'est ce qui separe « cette page
 *     existe et n'est pas la tienne » de « cette URL n'existe pas ». Mesure :
 *     `/org/ofsh/agenda` resout `organization.events.agenda` ;
 *     `/org/ofsh/page-inexistante` ne resout RIEN. Sans ce predicat, l'ecart de
 *     slug seul confondrait refus d'acces et ressource inexistante.
 *
 *  3. **le slug de l'URL differe du sien**, compare comme une CHAINE.
 *
 * ## Aucun oracle d'existence — l'invariant a ne jamais casser
 *
 * Le point 3 ne lit JAMAIS l'Organization visee en base, et c'est tout
 * l'enjeu. `/org/<slug-inexistant>/agenda` resout la meme route et rend donc
 * une page IDENTIQUE a `/org/ofsh/agenda` : la presentation ne distingue pas
 * une Organization qui existe d'une qui n'existe pas.
 *
 * C'est la raison pour laquelle T1606 a garde le 404 plutot qu'un 403 — « un
 * 403 dirait "il y a quelque chose ici, mais pas pour vous" ». Resoudre le
 * slug ici rouvrirait par la petite porte ce que cette doctrine a ferme.
 *
 * Contrepartie assumee : sur un slug inexistant, « vous n'etes pas membre de
 * l'organisation » est imprecis. Il ne REVELE rien, et c'est le prix du
 * non-oracle.
 */
final class CrossTenantRefusal
{
    /** La page d'erreur doit-elle etre celle d'un espace etranger ? */
    public static function detect(): bool
    {
        return self::ownOrganization() !== null && self::urlSlugDiffers();
    }

    /** L'Organization de l'utilisateur connecte, ou null. */
    public static function ownOrganization(): ?Organization
    {
        $organization = auth()->user()?->organization;

        return $organization instanceof Organization ? $organization : null;
    }

    /**
     * Ou renvoyer la personne : le tableau de bord de SON Organization —
     * une destination ou l'on reprend son travail, pas une page d'accueil.
     *
     * `null` quand il n'y en a pas : l'appelant garde alors sa sortie
     * habituelle plutot qu'une URL fabriquee.
     */
    public static function ownDashboardUrl(): ?string
    {
        $own = self::ownOrganization();

        return $own !== null && Route::has('organization.dashboard')
            ? route('organization.dashboard', ['organization' => $own->slug])
            : null;
    }

    /**
     * Le slug porte par l'URL differe-t-il de celui de SON Organization ?
     *
     * Lu sur le PARAMETRE de route (`organization`, ou `community` pour la
     * forme heritee) plutot que sur un segment d'URL : c'est le nom declare,
     * il vaut pour toutes les formes de prefixe, et il est absent — donc la
     * reponse est non — des qu'aucune route ne l'a defini.
     */
    private static function urlSlugDiffers(): bool
    {
        $route = request()->route();

        if ($route === null) {
            return false;
        }

        $slug = $route->parameter('organization') ?? $route->parameter('community');

        // Defensif : si un jour ce parametre devenait lie au modele, on prend
        // son slug plutot que de comparer un objet a une chaine.
        if ($slug instanceof Organization) {
            $slug = $slug->slug;
        }

        return is_string($slug)
            && $slug !== ''
            && $slug !== self::ownOrganization()?->slug;
    }
}
