<?php

namespace App\Services\Ai;

use App\Models\Organization;
use App\Models\User;

/**
 * Perimetre ECONOMIQUE d'un appel herite passant par
 * `SupervisionProviderResolver` (TASK-1250) : qui est le tenant de la
 * depense, qui l'a declenchee, a qui le credit IA s'applique, et quelle
 * fonction produit l'emet. Tout est EXPLICITE et pose par l'appelant — rien
 * n'est devine depuis `current_organization` ni depuis `auth()`.
 *
 * - `organization` : le tenant de record de la ligne du ledger canonique
 *   `ai_provider_invocations` et de la trace operationnelle
 *   `admin_ai_interactions`. C'est SON budget mensuel (`organization_ai_
 *   settings.monthly_budget_usd`) que la garde applique. Le payeur de la
 *   facture provider reste la PLATEFORME (`credential_source = platform`,
 *   declare, jamais deduit) : les deux informations coexistent en base.
 * - `actor` : l'utilisateur qui a declenche l'appel (`user_id` du ledger).
 *   NULL seulement pour un traitement sans utilisateur.
 * - `creditUser` : l'utilisateur dont le CREDIT IA mensuel (T1229) est
 *   evalue. NULL = aucun credit applique — reserve aux bancs d'administration
 *   (comme le bac a sable de doctrine), jamais a un chemin membre.
 * - `feature` : fonction produit emettrice (`ai_provider_invocations.feature`),
 *   stable et lisible : `service_offer_formulation`, `admin_ai_supervision_bench`…
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
    }
}
