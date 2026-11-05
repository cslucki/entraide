<?php

namespace App\Support\Entraide;

/**
 * TASK-1754 (§9.1 du CDC) — dire le TEMPS, pas un prix.
 *
 * ## Pourquoi cette classe existe
 *
 * « 1 point = 1 minute » est deja la regle officielle du produit — elle est
 * ecrite dans les invites IA de formulation (`ServiceController::formulate()`).
 * Mais l'ecran, lui, dit « 30 pts » comme une etiquette de prix. Donner une
 * heure de son temps n'est pas payer soixante points, et c'est toute la
 * difference entre une plateforme d'entraide et une place de marche.
 *
 * ## Ce que cette classe NE fait PAS
 *
 * Elle ne touche AUCUNE donnee. `points_cost`, `points_proposed` et
 * `points_agreed` restent des entiers, le ledger reste en points, les soldes
 * aussi. C'est un FORMATEUR D'AFFICHAGE, et rien d'autre : le jour ou l'on
 * voudra revenir en arriere, il n'y aura pas une ligne de base a reprendre.
 *
 * ## Le double affichage est une DECISION, pas une hesitation
 *
 * Arbitrage de Cyril (Q8, 05/11) : « 30 min · 30 pts » plutot que « 30 min »
 * seul. Les membres ont leurs reperes en points depuis l'ouverture du site ;
 * les leur retirer d'un coup, c'est leur demander de reapprendre a lire leur
 * solde. Le temps s'installe a cote, la bascule complete reste possible plus
 * tard — et elle ne coutera qu'un changement ICI.
 */
final class TempsPartage
{
    /** Un point vaut une minute. La regle du produit, pas un reglage. */
    public const MINUTES_PAR_POINT = 1;

    /**
     * Le temps seul : « 30 min », « 1 h », « 1 h 30 », « Offert ».
     *
     * Zero n'est pas « 0 min » : c'est un coup de main OFFERT (§9.3), et le
     * dire en minutes nulles serait absurde. L'appelant qui veut vraiment
     * « 0 min » passe `$offertSiZero: false`.
     */
    public static function duree(?int $points, bool $offertSiZero = true): string
    {
        $minutes = max(0, (int) $points) * self::MINUTES_PAR_POINT;

        if ($minutes === 0 && $offertSiZero) {
            return __('entraide.temps.offert');
        }

        if ($minutes < 60) {
            return __('entraide.temps.minutes', ['n' => $minutes]);
        }

        $heures = intdiv($minutes, 60);
        $reste = $minutes % 60;

        return $reste === 0
            ? __('entraide.temps.heures', ['n' => $heures])
            : __('entraide.temps.heures_minutes', ['h' => $heures, 'm' => $reste]);
    }

    /**
     * Le double affichage retenu par Cyril : « 30 min · 30 pts ».
     *
     * Un coup de main offert n'affiche pas « · 0 pts » — il n'y a pas de
     * points a montrer, et la mention affaiblirait le geste.
     */
    public static function libelle(?int $points, bool $offertSiZero = true): string
    {
        $n = max(0, (int) $points);

        if ($n === 0 && $offertSiZero) {
            return __('entraide.temps.offert');
        }

        // `trans_choice` et non `__` : « 1 min · 1 pt » et non « 1 pts ».
        return trans_choice('entraide.temps.double', $n, [
            'temps' => self::duree($n, false),
            'points' => $n,
        ]);
    }

    /** « Votre temps disponible : 40 min » — le solde, dit en temps. */
    public static function solde(?int $points): string
    {
        // Un solde a zero est un vrai zero : « Offert » n'aurait aucun sens ici.
        return self::libelle($points, offertSiZero: false);
    }

    /** Le geste est-il un coup de main offert ? */
    public static function estOffert(?int $points): bool
    {
        return max(0, (int) $points) === 0;
    }
}
