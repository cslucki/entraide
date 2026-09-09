<?php

namespace App\Support\Ai;

use Carbon\CarbonImmutable;

/**
 * TASK-1487 — QUI peut recevoir un verdict humain, et DEPUIS QUAND.
 *
 * ## Pourquoi une date, et pas seulement une liste
 *
 * Sans elle, un cockpit dirait de `clarify_help_request` : « 87 interactions,
 * 0 evaluee, 0 % de couverture ». Ce serait faux — pas d'un chiffre, mais de
 * nature. Ces 87 tours sont ANTERIEURS a l'instrumentation : ils ne sont pas
 * « non evalues », ils sont **inevaluables**. Personne n'a jamais pu donner un
 * avis dessus, et personne ne le pourra jamais.
 *
 * Le CDC interdit d'afficher 0 quand la verite est « non mesure ». La date est
 * ce qui rend cette distinction calculable au lieu d'etre une precaution de
 * vocabulaire.
 *
 * ## Les dates sont des FAITS, pas des estimations
 *
 * Chacune est celle du commit qui a branche le premier ecrivain de verdict sur
 * cette fonction :
 *
 *  - `blog_explorer` .......... 2026-08-19, TASK-1256 (`BlogExplorerController`)
 *  - `clarify_help_request` ... 2026-09-09, TASK-1486 (`AiShell::judge()`)
 *
 * ## Ce que cette classe n'est pas
 *
 * Ni une autorite d'acces, ni un registre de capabilities — `CapabilityRegistry`
 * reste seul a dire ce que l'IA a le droit de faire. Elle ne repond qu'a une
 * question de MESURE : peut-on savoir si cette fonction aide ?
 *
 * Un test verifie que cette declaration correspond au code reel : toute
 * fonction declaree doit avoir un ecrivain, et tout ecrivain doit etre declare.
 * Une liste ecrite a la main qui derive du code est un mensonge en sursis.
 */
final class AiQualityInstrumentation
{
    /** La fonction n'a aucun moyen de recueillir un verdict. */
    public const STATUS_NOT_INSTRUMENTED = 'not_instrumented';

    /** Instrumentee, mais aucun de ses tours de la periode n'etait evaluable. */
    public const STATUS_NOT_YET_MEASURABLE = 'not_yet_measurable';

    /** Evaluable, et personne n'a encore repondu. */
    public const STATUS_NO_FEEDBACK_YET = 'no_feedback_yet';

    /** Au moins un verdict humain existe. */
    public const STATUS_MEASURED = 'measured';

    /**
     * fonction => date a partir de laquelle un verdict devient possible.
     *
     * @var array<string, string>
     */
    private const SINCE = [
        'blog_explorer' => '2026-08-19T16:23:07+02:00',
        'clarify_help_request' => '2026-09-09T14:00:37+02:00',
    ];

    /** @return list<string> */
    public static function features(): array
    {
        return array_keys(self::SINCE);
    }

    public static function isInstrumented(string $feature): bool
    {
        return array_key_exists($feature, self::SINCE);
    }

    /**
     * Depuis quand un verdict est possible sur cette fonction, ou `null`.
     *
     * RENDUE DANS LE FUSEAU DE L'APPLICATION, et ce n'est pas cosmetique. Les
     * constantes portent un decalage `+02:00` — l'heure de Paris ou le commit a
     * ete fait. Comparee telle quelle a un `created_at` stocke en UTC, elle
     * partait en binding SQL sous sa forme LOCALE (`14:00:37`) face a une
     * colonne UTC (`12:00:38`) : deux heures d'ecart, et des tours reellement
     * evaluables comptes comme inevaluables.
     *
     * Le test l'a trouve — pas la relecture.
     */
    public static function since(string $feature): ?CarbonImmutable
    {
        return isset(self::SINCE[$feature])
            ? CarbonImmutable::parse(self::SINCE[$feature])->setTimezone(config('app.timezone', 'UTC'))
            : null;
    }

    /**
     * Le statut d'une fonction sur une periode.
     *
     * L'ordre des tests n'est pas cosmetique. « Non instrumentee » passe
     * d'abord : c'est un fait structurel, pas un manque de retours. Puis
     * « pas encore evaluable » : la fonction sait recueillir un avis, mais
     * aucun tour de CETTE periode ne pouvait en recevoir. Ensuite seulement on
     * parle de retours.
     *
     * @param  int  $evaluableInteractions  tours de la periode posterieurs a l'instrumentation
     * @param  int  $evaluated  tours de la periode portant au moins un verdict
     */
    public static function status(string $feature, int $evaluableInteractions, int $evaluated): string
    {
        if (! self::isInstrumented($feature)) {
            return self::STATUS_NOT_INSTRUMENTED;
        }

        if ($evaluableInteractions === 0) {
            return self::STATUS_NOT_YET_MEASURABLE;
        }

        return $evaluated > 0 ? self::STATUS_MEASURED : self::STATUS_NO_FEEDBACK_YET;
    }
}
