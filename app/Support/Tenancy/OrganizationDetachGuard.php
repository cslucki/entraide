<?php

namespace App\Support\Tenancy;

use App\Models\Organization;
use App\Rules\UniqueEmailInOrganization;

/**
 * TASK-1729 phase 2 — la garde D1, pour les chemins qui ne sont pas des ecrans.
 *
 * ## Le fait que cette classe existe pour rappeler
 *
 * `users.organization_id` porte `ON DELETE SET NULL`
 * (`2026_05_28_000002_drop_community_id_from_tables.php`). Supprimer une
 * Organization fabrique donc des orphelins **par la base**, sans qu'aucune
 * ligne de PHP n'ecrive dans `users`. Deux consequences, et il faut les tenir
 * ensemble :
 *
 *  1. **Un verrou ne suffit pas.** Il rend un controle fiable, il n'en tient
 *     pas lieu. Il n'existe aucun evenement de modele, aucune regle de
 *     validation, aucun index capable d'arreter ce `SET NULL` : seul un
 *     controle pose AVANT la suppression le peut.
 *  2. **Le controle ne suffit pas sans le verrou.** Entre lui et la
 *     suppression, une inscription concurrente peut creer le compte qui le
 *     rend faux — en READ COMMITTED, chaque instruction prend un instantane
 *     neuf.
 *
 * D'ou une seule methode qui fait les deux, dans l'ordre. Les separer aurait
 * permis d'en appeler une sans l'autre, et c'est exactement l'erreur que la
 * revue MASTER a relevee sur les deux premiers appelants : le controle y etait
 * juste, mais pose avant la transaction.
 *
 * ## Pourquoi la paire orphelin/membre n'est pas tenue par un index
 *
 * Elle porte sur deux lignes dont l'une a `organization_id` nul et l'autre
 * non. Aucun index unique ne l'exprime — ni composite, ni partiel. Elle est
 * donc tenue par l'application, et cette classe est l'un de ses six points
 * d'appui (les cinq portes d'ecriture d'email, et ici).
 */
class OrganizationDetachGuard
{
    /**
     * A appeler DANS une transaction, AVANT toute mutation.
     *
     * Prend le verrou puis refuse : l'ordre est la garantie, et il est
     * verifie par un test qui ecoute les requetes emises.
     *
     * @throws OrganizationDetachRefused
     */
    public static function verrouillerEtRefuserSiAmbigu(Organization $organization): void
    {
        UserWriteLock::acquire();

        $adresses = static::adressesDesComptesRattaches($organization);

        if ($adresses === []) {
            return;
        }

        $conflits = UniqueEmailInOrganization::adressesEnConflitHorsOrganization(
            $adresses,
            (string) $organization->getKey(),
        );

        if ($conflits->isNotEmpty()) {
            throw OrganizationDetachRefused::pourAdresses($organization, $conflits->all());
        }
    }

    /**
     * Les adresses encore rattachees a cette Organization.
     *
     * `User` ne porte ni `SoftDeletes` ni portee globale — verifie, parce que
     * l'un ou l'autre aurait rendu ce `pluck` aveugle a des lignes que la FK
     * detacherait quand meme. Le jour ou cela change, c'est ici qu'il faut
     * ajouter `withTrashed()` / `withoutGlobalScopes()`, et le test de
     * perimetre ci-contre doit rougir.
     *
     * @return list<string>
     */
    private static function adressesDesComptesRattaches(Organization $organization): array
    {
        return $organization->users()->pluck('email')->all();
    }
}
