<?php

namespace App\Support\Tenancy;

use App\Models\Organization;
use App\Support\ScenarioManager\SandboxGuard;

/**
 * TASK-1787 — LE predicat « peut-on créer un compte dans cette Organization ? »,
 * à un seul endroit.
 *
 * ## Pourquoi une classe pour si peu, et ce n'est pas une précaution de style
 *
 * Le dépôt a déjà payé cette leçon deux fois, et l'a écrite :
 *
 * `SandboxGuard` — « la même règle a dû être posée sur CINQ surfaces de
 * production différentes, et trois relectures successives en ont trouvé une
 * nouvelle à chaque passage (…). Chaque fois que je l'ai réécrite sur place, la
 * suivante a été oubliée. **Une règle de sécurité recopiée est une règle qui
 * diverge.** »
 *
 * `Api\AuthController` — « c'est déjà ce qui était arrivé à la garde sandbox de
 * TASK-1650, **posée sur une seule des deux portes**. »
 *
 * Il y a ici QUATRE portes vers `User::create()`, et trois doivent se fermer :
 * le formulaire web, l'API publique, l'acceptation d'invitation. La quatrième —
 * la création de comptes de test par le SuperAdmin — reste ouverte, exigence
 * explicite du CDC. Les quatre interrogent ce fichier plutôt que de recopier
 * trois conditions.
 *
 * ## Ce que la garde englobe, et pourquoi elle englobe la sandbox
 *
 * Deux raisons de refuser, et elles répondent à la même question :
 *
 * - `registration_disabled` — le réglage SuperAdmin de cette TASK ;
 * - **sandbox de scénario** — refus préexistant (T1650), repris ici parce qu'un
 *   appelant qui demande « puis-je créer un compte ? » veut UNE réponse, pas
 *   deux conditions à se rappeler d'enchaîner. La sandbox garde néanmoins son
 *   message propre : « monde de démonstration » et « inscriptions fermées » ne
 *   se disent pas pareil à qui lit l'écran.
 *
 * `SandboxGuard` reste l'autorité sur ce qu'est une sandbox ; on la lui demande,
 * on ne la redéfinit pas.
 *
 * ## Ce que la garde ne dit PAS
 *
 * Rien sur la CONNEXION. `main` reste publique et reste dans les sélecteurs de
 * connexion — `LoginAccountResolver` résout par adresse et n'interroge jamais ce
 * fichier. Fermer l'inscription ne ferme pas la porte à qui a déjà un compte :
 * c'est tout le sens de la demande.
 */
final class RegistrationGuard
{
    /**
     * Peut-on créer un compte dans cette Organization ?
     *
     * `null` rend `false` : pas d'Organization, pas de compte. Fail-closed, et
     * c'est la bonne réponse — un appelant sans tenant résolu ne doit pas créer.
     */
    public static function estOuverteAuxInscriptions(?Organization $organization): bool
    {
        if ($organization === null) {
            return false;
        }

        if (SandboxGuard::estUneSandbox($organization)) {
            return false;
        }

        return ! $organization->registration_disabled;
    }

    /** L'inverse, pour que les sites d'appel se lisent dans le sens du refus. */
    public static function estFermeeAuxInscriptions(?Organization $organization): bool
    {
        return ! self::estOuverteAuxInscriptions($organization);
    }

    /**
     * Cette Organization est-elle fermée PAR LE REGLAGE, et non parce qu'elle
     * est une sandbox ?
     *
     * Les deux refus ne se racontent pas pareil à l'écran : « monde de
     * démonstration » n'est pas « les inscriptions sont fermées ». Les sites
     * d'appel qui rendent un message distinguent donc les deux causes, sans
     * avoir à relire eux-mêmes la colonne.
     */
    public static function fermeeParLeReglage(?Organization $organization): bool
    {
        return $organization !== null
            && ! SandboxGuard::estUneSandbox($organization)
            && (bool) $organization->registration_disabled;
    }

    /**
     * La phrase rendue à qui se présente devant une Organization fermée.
     *
     * Elle nomme l'Organization : la personne est arrivée là délibérément, le
     * plus souvent par un lien, et un refus anonyme l'empêcherait de comprendre
     * ce qui lui arrive.
     */
    public static function messageFermeture(Organization $organization): string
    {
        return __('auth.registration_closed_for', ['organization' => $organization->name]);
    }
}
