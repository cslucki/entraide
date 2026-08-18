<?php

namespace App\Support\Ai;

/**
 * Credit IA EFFECTIF d'un utilisateur dans une Organization (TASK-1229) :
 * le resultat de la cascade parametre plateforme -> override Organization.
 *
 * Unite : NOMBRE D'UTILISATIONS par mois (jamais une monnaie — un credit
 * commercial n'est pas un cout, et un appel au cout non mesurable reste une
 * utilisation). `monthlyUses === null` = illimite / inclus : aucun blocage.
 */
final class AiUserCreditPolicy
{
    public const SOURCE_PLATFORM = 'platform';

    public const SOURCE_ORGANIZATION = 'organization';

    public function __construct(
        /** null = illimite (inclus). 0 = aucune utilisation incluse. */
        public readonly ?int $monthlyUses,
        /** Plateforme ou override d'Organization. */
        public readonly string $source,
        /** Le reglage plateforme brut : IA gratuite activee ? */
        public readonly bool $freeEnabled,
        /** Quota plateforme brut (null = illimite), avant desactivation. */
        public readonly ?int $platformMonthlyUses,
        /** Seuil d'alerte, en pourcentage du quota (1..99). */
        public readonly int $alertPercent,
        /** A quota atteint : proposer un abonnement (bouton « Voir les offres »). */
        public readonly bool $offerSubscription,
    ) {}

    public function isUnlimited(): bool
    {
        return $this->monthlyUses === null;
    }
}
