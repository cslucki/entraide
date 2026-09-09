<?php

namespace App\Support\Loops;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * TASK-1476 — la periode d'un « Rattrape-moi depuis… », TOUJOURS explicite.
 *
 * ## Pourquoi une classe pour une soustraction de dates
 *
 * Parce que la promesse tentante — « depuis votre derniere visite » — est
 * interdite, et qu'elle est interdite pour une raison mesurable : **aucune
 * position de lecture n'existe** dans ce depot pour le contenu d'une Boucle.
 * `messages.read_at` couvre la messagerie 1-a-1, `member_notifications.read_at`
 * les notifications, `guest_visitors.last_seen_at` les visiteurs publics. Rien
 * ne dit ce qu'un membre a lu dans une Boucle.
 *
 * Un produit qui afficherait « depuis votre derniere visite » inventerait donc
 * une autorite qu'il n'a pas. Cette classe rend l'alternative honnete
 * STRUCTURELLE : la fenetre ne peut naitre que d'un choix explicite, et elle
 * porte ses deux bornes pour que l'ecran puisse les nommer.
 *
 * ## Ce qu'elle refuse
 *
 * - une date dans le futur (il n'y a rien a rattraper) ;
 * - une profondeur hors de {@see self::ALLOWED_DAYS} ou au-dela d'un an ;
 * - une valeur illisible.
 *
 * Dans les trois cas elle retombe sur le defaut de 7 jours plutot que d'echouer :
 * un rattrapage n'est pas un formulaire a valider, et une fenetre par defaut
 * NOMMEE reste honnete.
 */
final class LoopCatchUpWindow
{
    /** Le defaut demande : une semaine. */
    public const DEFAULT_DAYS = 7;

    /** Les profondeurs proposees a l'ecran. */
    public const ALLOWED_DAYS = [7, 14, 30, 90];

    /** Au-dela, ce n'est plus un rattrapage : c'est l'historique de la Boucle. */
    public const MAX_DAYS = 365;

    private function __construct(
        public readonly CarbonImmutable $since,
        public readonly CarbonImmutable $until,
        /** La profondeur en jours quand elle vient d'un preset, `null` si l'utilisateur a pose une date. */
        public readonly ?int $days,
        /** Vrai quand aucune entree exploitable n'a ete fournie : l'ecran doit le dire. */
        public readonly bool $isDefault,
    ) {}

    /**
     * Lit la fenetre d'une requete. `since` (une date) l'emporte sur `days`
     * (un preset) : c'est le geste le plus precis de l'utilisateur.
     */
    public static function fromRequest(Request $request, ?CarbonImmutable $now = null): self
    {
        $now = $now ?? CarbonImmutable::now();

        $since = $request->query('since');

        if (is_string($since) && $since !== '') {
            $parsed = self::parseDate($since);

            if ($parsed !== null && $parsed->lessThanOrEqualTo($now) && $parsed->greaterThanOrEqualTo($now->subDays(self::MAX_DAYS))) {
                return new self($parsed->startOfDay(), $now, null, false);
            }

            return self::default($now);
        }

        $days = $request->query('days');

        if (is_numeric($days) && in_array((int) $days, self::ALLOWED_DAYS, true)) {
            return self::ofDays((int) $days, $now);
        }

        return self::default($now);
    }

    public static function ofDays(int $days, ?CarbonImmutable $now = null): self
    {
        $now = $now ?? CarbonImmutable::now();

        return new self($now->subDays($days)->startOfDay(), $now, $days, $days === self::DEFAULT_DAYS);
    }

    public static function default(?CarbonImmutable $now = null): self
    {
        return self::ofDays(self::DEFAULT_DAYS, $now);
    }

    private static function parseDate(string $value): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
