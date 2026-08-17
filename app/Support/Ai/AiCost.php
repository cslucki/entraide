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
    /**
     * TASK-1220 : provenance d'un cout CONNU, pour le ledger canonique.
     * `provider_reported` = le provider a communique ce montant ;
     * `catalog_estimated` = calcule depuis `usage x tarif catalogue` (un tarif
     * `free` du catalogue inclus : c'est le catalogue qui l'affirme).
     * Un cout unknown n'a pas de provenance (`source` = null).
     *
     * Posee UNIQUEMENT par les deux primitives qui savent :
     * `AiEconomicGuard::finalize()` (cout rapporte) et
     * `AiPricingCatalog::cost()` (catalogue). Jamais reconstruite ailleurs —
     * c'etait exactement la dette « provenance du cout » notee en TASK-1219.
     */
    public const SOURCE_PROVIDER_REPORTED = 'provider_reported';

    public const SOURCE_CATALOG_ESTIMATED = 'catalog_estimated';

    private function __construct(
        public readonly ?float $costUsd,
        public readonly bool $costUnknown,
        public readonly ?string $reason,
        public readonly ?string $source,
    ) {}

    /**
     * Cout mesure. Zero n'est accepte ici que parce que l'appelant a etabli que
     * le tarif est reellement nul (execution locale, reponse sans LLM).
     *
     * `$source` : provenance de la mesure (constantes SOURCE_*), null pour les
     * appelants historiques qui ne la declarent pas encore.
     */
    public static function known(float $costUsd, ?string $source = null): self
    {
        return new self($costUsd, false, null, $source);
    }

    /**
     * Cout non mesurable. `$reason` est un identifiant technique stable, jamais
     * un texte d'interface (cf. constantes REASON_* de AiPricingCatalog).
     */
    public static function unknown(string $reason): self
    {
        return new self(null, true, $reason, null);
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
