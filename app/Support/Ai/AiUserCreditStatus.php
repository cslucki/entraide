<?php

namespace App\Support\Ai;

use Carbon\CarbonImmutable;

/**
 * Etat du credit IA d'UN utilisateur sur la fenetre courante (TASK-1229) :
 * la politique effective + le compte d'utilisations deja emises. Calcule par
 * `AiEconomicGuard::userCreditStatus()` — l'autorite qui APPLIQUE le credit —
 * et rendu tel quel aux ecrans : le chiffre affiche est celui qui bloque.
 *
 * Fenetre = celle du budget Organization (mois UTC), jamais une autre.
 */
final class AiUserCreditStatus
{
    public function __construct(
        public readonly AiUserCreditPolicy $policy,
        /** Utilisations emises ce mois : generations (hors essais de doctrine) + recherches documentaires. */
        public readonly int $used,
        public readonly CarbonImmutable $periodStart,
        /** Debut de la fenetre suivante = date de renouvellement. */
        public readonly CarbonImmutable $renewsAt,
    ) {}

    public function quota(): ?int
    {
        return $this->policy->monthlyUses;
    }

    public function isUnlimited(): bool
    {
        return $this->policy->isUnlimited();
    }

    /** null = illimite. */
    public function remaining(): ?int
    {
        return $this->isUnlimited() ? null : max(0, (int) $this->quota() - $this->used);
    }

    /** null = illimite ou quota nul. */
    public function ratio(): ?float
    {
        $quota = $this->quota();

        return $quota === null || $quota <= 0 ? null : $this->used / $quota;
    }

    /** null = illimite ou quota nul ; non plafonne (150 % s'affiche 150 %). */
    public function percent(): ?float
    {
        $ratio = $this->ratio();

        return $ratio === null ? null : round($ratio * 100, 1);
    }

    /** Le plafond est atteint : la prochaine utilisation sera refusee. */
    public function isExhausted(): bool
    {
        return ! $this->isUnlimited() && $this->used >= (int) $this->quota();
    }

    /** Seuil d'alerte franchi, plafond pas encore atteint : message calme, action non bloquee. */
    public function isAlerting(): bool
    {
        $ratio = $this->ratio();

        return $ratio !== null && ! $this->isExhausted() && $ratio >= $this->policy->alertPercent / 100;
    }

    /**
     * Forme publique, sans aucun secret ni chiffre d'Organization : ce que
     * l'ecran et les reponses JSON transportent.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'unlimited' => $this->isUnlimited(),
            'quota' => $this->quota(),
            'used' => $this->used,
            'remaining' => $this->remaining(),
            'percent' => $this->percent(),
            'alert' => $this->isAlerting(),
            'exhausted' => $this->isExhausted(),
            'alert_percent' => $this->policy->alertPercent,
            'offer_subscription' => $this->policy->offerSubscription,
            'source' => $this->policy->source,
            'renews_at' => $this->renewsAt->toIso8601String(),
        ];
    }
}
