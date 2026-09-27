<?php

namespace App\Support\ScenarioManager;

use App\Models\Organization;
use App\Models\User;

/**
 * TASK-1650 — LE predicat « est-ce une sandbox de scenario ? », a un seul
 * endroit.
 *
 * ## Pourquoi une classe pour si peu
 *
 * Parce que la meme regle a du etre posee sur CINQ surfaces de production
 * differentes, et que trois relectures successives en ont trouve une nouvelle
 * a chaque passage : `/admin/users`, la suppression d'Organization,
 * l'inscription web, l'inscription API, et l'affectation de donnees en masse.
 *
 * Chaque fois que je l'ai reecrite sur place, la suivante a ete oubliee. Une
 * regle de securite recopiee est une regle qui diverge : T1639 a deja coute
 * exactement ca — un comptage et un transfert qui ne portaient pas le meme
 * filtre.
 *
 * ## Ce qui rend une sandbox reconnaissable
 *
 * `scenario_sandbox_created_at`, pose par le SEUL provisionneur de sandbox et
 * absent de `$fillable`. Ni le nom, ni le slug, ni `is_public` : le slug se
 * modifie, et `is_public` sert deja a autre chose.
 *
 * ## Pourquoi fermer les ENTREES, alors que le preflight detecte deja
 *
 * Detecter n'est pas empecher. Et depuis T1650 les SORTIES sont fermees :
 * un compte reel place dans une sandbox ne peut plus en ressortir par
 * `/admin/users`, la sandbox ne peut plus etre supprimee par l'ecran
 * generique, et le preflight refuse Reset comme Remove tant qu'elle contient
 * du contenu etranger. Une entree laissee ouverte ne fabrique donc plus une
 * fuite : elle fabrique un PIEGE — des comptes reels enfermes dans un monde
 * de demonstration que plus aucun geste ne sait retirer.
 *
 * C'est pour cela que chaque entree doit etre fermee, et que le preflight
 * seul ne suffit pas.
 */
final class SandboxGuard
{
    /**
     * Cette Organization est-elle une sandbox de scenario ?
     */
    public static function estUneSandbox(?Organization $organization): bool
    {
        return $organization !== null && $organization->scenario_sandbox_created_at !== null;
    }

    /**
     * Ce compte appartient-il aujourd'hui a une sandbox ?
     *
     * Lu en `withTrashed()` : une sandbox en corbeille reste une sandbox, et
     * c'est meme l'etat ou il ne faut surtout pas laisser deplacer ses
     * comptes.
     */
    public static function appartientAUneSandbox(?User $user): bool
    {
        if ($user === null || $user->organization_id === null) {
            return false;
        }

        return self::estUneSandbox(
            Organization::query()->withTrashed()->find($user->organization_id)
        );
    }

    /**
     * La phrase rendue a l'operateur quand une sandbox est la DESTINATION.
     */
    public static function messageDestination(Organization $sandbox): string
    {
        return sprintf(
            "« %s » est une sandbox de scenario : on n'y place pas de compte depuis cet ecran. Les personas d'une sandbox sont geres par le Scenario Manager.",
            $sandbox->name
        );
    }
}
