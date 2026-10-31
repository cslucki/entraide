<?php

namespace App\Services\Users;

use App\Models\User;

/**
 * TASK-1730 (A5a) — le compte cible est-il utilisable, AVANT de basculer ?
 *
 * ## Pourquoi ce pre-controle existe
 *
 * Quatre des cinq situations bloquantes ne sont PAS verifiees a la connexion :
 * elles vivent dans le groupe de middlewares `web` (voir `bootstrap/app.php`),
 * donc elles ne se declenchent qu'a la requete SUIVANTE. Seul `banned_at` est
 * controle par `LoginRequest::authenticate()`.
 *
 * Sans ce pre-controle, une bascule « reussirait », detruirait la session
 * d'origine, et la personne ne decouvrirait le refus qu'une requete plus tard
 * — echouee dans un compte inutilisable, sans chemin de retour, puisque la
 * bascule est un VRAI login et non un mode persona (arbitrage MASTER D5).
 * C'est precisement ce que D4 veut eviter.
 *
 * ## Ce qu'il n'est pas
 *
 * Il ne REMPLACE pas les middlewares : ils restent en place et continuent de
 * faire autorite a chaque requete. Il anticipe leur verdict pour ne pas
 * detruire une session utilisable au profit d'une session morte.
 *
 * ## Aucune regle n'est reecrite ici
 *
 * Les cinq conditions sont celles que portent deja le modele et les gardes :
 * `banned_at`, `estFermeVolontairement()`, `must_set_password`,
 * `hasVerifiedEmail()`, `attendApprobation()`. Recopier leur LOGIQUE aurait
 * donne deux regles pour une idee — et c'est la copie qu'on oublie de
 * corriger. On les COMPOSE.
 *
 * `attendApprobation()` merite une mention : il lit `requires_account_approval`
 * sur l'Organization DU COMPTE. Interroge sur le compte cible, il repond donc
 * sur le tenant d'arrivee, ce qui est exactement la question posee.
 */
class AccountSwitchPreflight
{
    /**
     * Le motif de refus, ou `null` si la bascule peut avoir lieu.
     *
     * Le motif est destine au JOURNAL, pas a l'ecran : l'arbitrage MASTER D4
     * exige un message externe non enumerant. Dire « ce compte attend une
     * approbation » confirmerait l'existence d'un compte dans ce tenant.
     */
    public function motifDeRefus(User $cible): ?string
    {
        if ($cible->banned_at !== null) {
            // Les deux moitiés de TASK-1716 : la colonne ne disait pas QUI
            // avait decide, et le discriminant est `self_closed_at`.
            return $cible->estFermeVolontairement() ? 'closed_by_owner' : 'banned';
        }

        if ($cible->must_set_password) {
            return 'invitation_password_pending';
        }

        if (! $cible->hasVerifiedEmail()) {
            return 'email_not_verified';
        }

        if ($cible->attendApprobation()) {
            return 'pending_approval';
        }

        return null;
    }
}
