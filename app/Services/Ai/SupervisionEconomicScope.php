<?php

namespace App\Services\Ai;

use App\Models\Organization;
use App\Models\User;

/**
 * Perimetre ECONOMIQUE d'un appel herite paye par la cle plateforme
 * (TASK-1250) : qui est le tenant de la depense, qui l'a declenchee, a qui
 * le credit IA s'applique, et quelle fonction produit l'emet. Tout est
 * EXPLICITE et pose par l'appelant — rien n'est devine depuis
 * `current_organization` ni depuis `auth()`.
 *
 * REGLE D'ATTRIBUTION CANONIQUE (TASK-1253, definitive — verifiee sur les
 * neuf writers du ledger `ai_provider_invocations`, chemins canoniques et
 * herites confondus ; `docs/ai/OBSERVABILITE-COUTS.md`, « Attribution
 * canonique ») :
 *
 * - `organization` : le tenant de record = l'Organization de l'OBJET sur
 *   lequel l'IA travaille (la Boucle, le Dossier, l'article, le PROFIL dont
 *   l'agent repond, l'Organization administree, l'Organization PLATEFORME
 *   pour le banc SuperAdmin) — jamais celle de l'utilisateur connecte
 *   « parce qu'il est connecte », jamais celle d'un visiteur. C'est SON
 *   budget mensuel (`organization_ai_settings.monthly_budget_usd`) que la
 *   garde applique, et c'est dans SA politique de credit (T1229) que le
 *   credit de l'acteur est evalue — compteur (tenant, acteur), jamais une
 *   lecture dans l'Organization d'origine de l'acteur (Organization = Tenant,
 *   aucune lecture cross-tenant). Le payeur de la facture provider reste la
 *   PLATEFORME (`credential_source = platform`, declare, jamais deduit) :
 *   tenant de record et payeur de la facture coexistent en base, ce sont
 *   deux informations.
 * - `actor` : l'utilisateur qui a DECLENCHE l'appel (`user_id` du ledger) —
 *   l'expediteur du message dans une Boucle agent, le visiteur authentifie
 *   du chat de profil, le membre qui formule, l'administrateur qui teste.
 *   NULL seulement pour un traitement sans utilisateur.
 * - `creditUser` : l'utilisateur dont le CREDIT IA mensuel (T1229) est
 *   evalue. Sur un chemin MEMBRE c'est l'acteur lui-meme (celui qui
 *   interroge l'IA consomme son credit ; le proprietaire d'un profil ne porte
 *   pas le credit de ses visiteurs). NULL = aucun credit applique — reserve
 *   aux bancs d'administration (comme le bac a sable de doctrine), jamais a
 *   un chemin membre. INVARIANT : `creditUser` est NULL ou EST l'acteur.
 *   Le ledger n'a qu'une colonne `user_id` : elle dit a la fois qui a agi et
 *   qui a paye son credit. Un credit porte par quelqu'un d'autre que l'acteur
 *   rendrait cette colonne illisible economiquement — ce besoin, s'il
 *   apparait un jour, exige une colonne de plus ET la levee consciente de cet
 *   invariant, pas un contournement.
 * - `feature` : fonction produit emettrice (`ai_provider_invocations.feature`),
 *   stable et lisible : `service_offer_formulation`, `admin_ai_supervision_bench`,
 *   `member_profile_agent_loop_reply`… Sur ces chemins `capability` est NULL
 *   (aucune capability canonique n'existe pour eux, dit tel quel) : la
 *   fonction produit d'une ligne se lit `COALESCE(feature, capability)`.
 */
final class SupervisionEconomicScope
{
    public function __construct(
        public readonly Organization $organization,
        public readonly ?User $actor,
        public readonly ?User $creditUser,
        public readonly string $feature,
    ) {
        if (trim($feature) === '') {
            throw new \InvalidArgumentException('An economic scope requires an explicit feature.');
        }

        // TASK-1253 : le credit est porte par l'acteur ou par personne.
        if ($creditUser !== null && ($actor === null || (string) $creditUser->getKey() !== (string) $actor->getKey())) {
            throw new \InvalidArgumentException(
                'An economic scope credits the actor or nobody: creditUser must be NULL or the actor itself.'
            );
        }
    }
}
