<?php

namespace App\Support\Onboarding;

use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\User;

/**
 * TASK-1361 — les etapes d'installation d'un membre, en UN SEUL endroit.
 *
 * ## Pourquoi cette classe existe
 *
 * Ces quatre etats etaient calcules EN LIGNE dans `DashboardController::index()`,
 * et n'existaient nulle part ailleurs. Le Shell ne pouvait donc pas repondre
 * « je commence par quoi ? » sans recopier la definition — c'est-a-dire sans
 * creer une SECONDE verite d'onboarding, condamnee a diverger de celle du
 * tableau de bord.
 *
 * Cette classe ne fait donc rien de neuf : elle DEPLACE la definition
 * existante pour que ses deux lecteurs lisent la meme.
 *
 * ## Ce qu'elle ne porte pas, et c'est deliberé
 *
 * Ni libelle, ni description, ni URL, ni appel a l'action. Uniquement des
 * cles et des booleens. Les textes restent dans `lang/{fr,en}/dashboard.php`
 * et les liens restent construits par le controleur, qui sait deja resoudre
 * la variante org-scopee. Un support partage qui porterait de la prose
 * deviendrait un second endroit ou l'ecrire.
 *
 * ## Ce qu'elle n'affirme pas
 *
 * Elle ne dit JAMAIS si quelqu'un est « nouveau ». Elle dit quelles etapes
 * sont faites. Le produit n'a aucun signal honnete de nouveaute — pas de
 * `last_login_at`, pas d'etat d'onboarding persiste — et `created_at` serait
 * un mauvais proxy : un compte dormant d'il y a un an n'est pas un nouvel
 * arrivant, un membre ajoute hier a une organisation mature en est un.
 * Deviner aurait donc etiquete la quasi-totalite des membres.
 */
final class MemberOnboardingSteps
{
    public const STEP_PRESENTATION = 'presentation';

    public const STEP_REQUEST = 'request';

    public const STEP_SERVICE = 'service';

    public const STEP_AI_PROFILE = 'ai_profile';

    /** L'ordre est celui du tableau de bord, et il fait partie du contrat. */
    public const KEYS = [
        self::STEP_PRESENTATION,
        self::STEP_REQUEST,
        self::STEP_SERVICE,
        self::STEP_AI_PROFILE,
    ];

    /**
     * Fait / pas fait, par cle, pour ce membre DANS cette Organization.
     *
     * `$aiProfile` est passe par l'appelant quand il l'a deja charge — le
     * tableau de bord le lit de toute facon pour son propre affichage, et une
     * extraction ne doit pas ajouter une requete a une page existante.
     *
     * @return array<string, bool>
     */
    public function doneFor(User $user, Organization $organization, ?MemberAiProfile $aiProfile = null): array
    {
        return [
            self::STEP_PRESENTATION => filled($user->bio),
            self::STEP_REQUEST => $user->serviceRequests()->where('organization_id', $organization->id)->exists(),
            self::STEP_SERVICE => $user->services()->where('organization_id', $organization->id)->exists(),
            self::STEP_AI_PROFILE => $aiProfile?->status === MemberAiProfile::STATUS_PUBLISHED,
        ];
    }

    /**
     * Les cles des etapes qui RESTENT, dans l'ordre du contrat.
     *
     * Une etape rendue indisponible par l'Organization — le profil IA quand
     * `ai_profiles_enabled` est faux — n'est pas « a faire » : la proposer
     * serait envoyer quelqu'un vers une porte fermee.
     *
     * @return list<string>
     */
    public function remainingFor(User $user, Organization $organization, ?MemberAiProfile $aiProfile = null): array
    {
        $done = $this->doneFor($user, $organization, $aiProfile);
        $remaining = [];

        foreach (self::KEYS as $key) {
            if ($key === self::STEP_AI_PROFILE && ! $organization->ai_profiles_enabled) {
                continue;
            }

            if ($done[$key] === false) {
                $remaining[] = $key;
            }
        }

        return $remaining;
    }
}
