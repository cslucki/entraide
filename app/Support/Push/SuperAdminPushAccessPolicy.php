<?php

namespace App\Support\Push;

use App\Models\User;

/**
 * TASK-1821 — qui peut ouvrir la console GLOBALE « Appareils Push », qui
 * voit et retire les abonnements de N'IMPORTE QUEL compte.
 *
 * ## Pourquoi `is_admin` seul ne suffit PAS ici
 *
 * `is_admin` ouvre déjà tout `/admin` — y compris, mesuré le 14/11 en base
 * locale, au moins deux comptes de DÉMONSTRATION (`admin@bouclepro.test`,
 * `launchpals.member1@bouclepro.test`) qui le portent. Les outils existants
 * de ce groupe ne lisent jamais les données d'un AUTRE utilisateur ; celui-ci,
 * par construction, lit — et peut retirer — les abonnements de tout le
 * monde. `is_admin` reste une condition NÉCESSAIRE (on ne l'ouvre à personne
 * d'autre), elle n'est plus SUFFISANTE.
 *
 * ## Le discriminant, et pourquoi il est fail-closed
 *
 * Même règle que `PushTestPolicy` (T1740) : `.test` est un TLD RÉSERVÉ par la
 * RFC 2606, qu'aucune adresse réelle ne peut enregistrer. Un compte
 * `is_admin=true` dont l'email finit en `.test` est donc un compte de
 * DÉMONSTRATION par construction, jamais le Superadmin réel — quelle que
 * soit la manière dont il a obtenu `is_admin`.
 *
 * Refuser est le défaut : un utilisateur absent, un email vide, ou qu'on ne
 * sait pas classer n'est JAMAIS traité comme autorisé.
 *
 * ## Pourquoi l'exclusion des `.test` ne vaut qu'HORS local
 *
 * MASTER (arbitrage T1821) demandait d'« empêcher qu'un compte de
 * démonstration puisse consulter ou supprimer les abonnements d'autres
 * membres EN PRODUCTION » — et la recette locale se fait justement AVEC un
 * compte de démonstration (`admin@bouclepro.test` est le compte connecté sur
 * `test.laravel`, mesuré le 14/11). Refuser les `.test` en local rendait donc
 * la console inaccessible à la seule personne censée la recetter, sans rien
 * protéger : en local, tout `/admin` est déjà ouvert à ce même compte.
 *
 * L'exclusion reste donc ENTIÈRE partout ailleurs — production comprise, qui
 * est la seule chose que l'arbitrage demandait de protéger.
 */
class SuperAdminPushAccessPolicy
{
    public function estAutorise(?User $utilisateur): bool
    {
        if ($utilisateur === null || ! $utilisateur->is_admin) {
            return false;
        }

        $email = mb_strtolower(trim((string) $utilisateur->email));

        if ($email === '') {
            return false;
        }

        // En local, un compte de démonstration est admis : c'est le compte de
        // recette, et l'environnement n'expose rien au-delà de la machine.
        if (app()->environment('local')) {
            return true;
        }

        return ! str_ends_with($email, '.test');
    }
}
