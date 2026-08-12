<?php

namespace App\Support\Ai;

/**
 * Verdict economique d'UN appel IA (TASK-1132 / IA P1-2).
 *
 * Repond a « combien cet appel a-t-il coute, ou peut-on le mesurer ? » avec
 * trois etats seulement, et jamais d'ambiguite entre eux :
 *
 *   known(0.0)  -> cout REELLEMENT nul     : cost_usd = 0,    cost_unknown = false
 *   known(x)    -> cout mesure             : cost_usd = x,    cost_unknown = false
 *   unknown()   -> cout non mesurable      : cost_usd = null, cost_unknown = true
 *
 * L'invariant central de P1-2 : `cost_unknown != cost 0`. Un cout inconnu ne
 * porte JAMAIS `cost_usd = 0`, sans quoi un tarif manquant serait indiscernable
 * d'un modele gratuit une fois la ligne ecrite en base.
 */
final class AiCost
{
    private function __construct(
        public readonly ?float $costUsd,
        public readonly bool $costUnknown,
        public readonly ?string $reason,
    ) {}

    /**
     * Cout mesure. Zero n'est accepte ici que parce que l'appelant a etabli que
     * le tarif est reellement nul (execution locale, reponse sans LLM).
     */
    public static function known(float $costUsd): self
    {
        return new self($costUsd, false, null);
    }

    /**
     * Cout non mesurable. `$reason` est un identifiant technique stable, jamais
     * un texte d'interface (cf. constantes REASON_* de AiPricingCatalog).
     */
    public static function unknown(string $reason): self
    {
        return new self(null, true, $reason);
    }

    public function isKnown(): bool
    {
        return ! $this->costUnknown;
    }

    /**
     * Attributs a fusionner dans une ecriture de trace. Les trois tables de
     * trace partagent ces deux colonnes : une seule representation, pas trois
     * variantes.
     *
     * @return array{cost_usd: ?float, cost_unknown: bool}
     */
    public function traceAttributes(): array
    {
        return [
            'cost_usd' => $this->costUsd,
            'cost_unknown' => $this->costUnknown,
        ];
    }
}
