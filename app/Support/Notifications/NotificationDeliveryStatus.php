<?php

namespace App\Support\Notifications;

/**
 * TASK-1377 — les etats d'une livraison, et les transitions permises.
 *
 * ## Pourquoi une autorite dediee plutot que des chaines libres
 *
 * Parce que les transitions comptent autant que les valeurs. « sent » qui
 * redevient « pending » n'est pas une valeur invalide, c'est un email envoye
 * deux fois. Les etats et leurs transitions vivent donc au meme endroit, et le
 * modele les applique.
 *
 * ## Trois familles, et la distinction est operationnelle
 *
 * - EN COURS : `pending`, `sending` — le travail n'est pas fini.
 * - TERMINE SANS ENVOI : `skipped_preference`, `skipped_unreachable` — decision
 *   normale, pas un incident. Un membre qui a coupe l'email n'est pas une panne.
 * - TERMINE AVEC TENTATIVE : `sent`, `failed`, `ambiguous`.
 *
 * ## `ambiguous` n'est PAS un `failed` prudent
 *
 * C'est l'etat ou l'on ne SAIT PAS si le message est parti — typiquement une
 * coupure apres remise au transport. Le confondre avec `failed` conduirait a
 * rejouer, donc a envoyer deux fois ; le confondre avec `sent` ferait croire a
 * une livraison qui n'a peut-etre pas eu lieu.
 *
 * Il n'est jamais rejoue automatiquement. Un humain tranche, plus tard, dans une
 * tranche dediee.
 *
 * ## Aucun rejeu automatique en V1-A, meme sur `failed`
 *
 * Un rejeu automatique suppose de savoir que rien n'est parti. Tant que cette
 * certitude n'est pas construite, rejouer est un pari sur le dos du
 * destinataire. `TERMINAUX` fige donc les cinq etats de fin.
 */
final class NotificationDeliveryStatus
{
    public const PENDING = 'pending';

    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const AMBIGUOUS = 'ambiguous';

    public const SKIPPED_PREFERENCE = 'skipped_preference';

    public const SKIPPED_UNREACHABLE = 'skipped_unreachable';

    /** @var list<string> */
    public const ALL = [
        self::PENDING,
        self::SENDING,
        self::SENT,
        self::FAILED,
        self::AMBIGUOUS,
        self::SKIPPED_PREFERENCE,
        self::SKIPPED_UNREACHABLE,
    ];

    /**
     * Les etats de FIN. Aucun n'est repris automatiquement en V1-A.
     *
     * @var list<string>
     */
    public const TERMINAUX = [
        self::SENT,
        self::FAILED,
        self::AMBIGUOUS,
        self::SKIPPED_PREFERENCE,
        self::SKIPPED_UNREACHABLE,
    ];

    /**
     * Les transitions permises, etat courant => etats atteignables.
     *
     * Volontairement pauvre : `pending` ne mene qu'a `sending`, et `sending` aux
     * etats de fin. Rien ne revient en arriere. Un etat terminal n'a AUCUNE
     * sortie — c'est ce qui rend `sent` irreversible, et ce qui empeche un rejeu
     * accidentel de reprendre une livraison deja tranchee.
     *
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        self::PENDING => [
            self::SENDING,
            // Une decision peut tomber avant meme la prise de travail : le
            // producteur sait deja que la preference l'interdit.
            self::SKIPPED_PREFERENCE,
            self::SKIPPED_UNREACHABLE,
        ],
        self::SENDING => [
            self::SENT,
            self::FAILED,
            self::AMBIGUOUS,
            self::SKIPPED_PREFERENCE,
            self::SKIPPED_UNREACHABLE,
        ],
        self::SENT => [],
        self::FAILED => [],
        self::AMBIGUOUS => [],
        self::SKIPPED_PREFERENCE => [],
        self::SKIPPED_UNREACHABLE => [],
    ];

    public static function existe(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    public static function estTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAUX, true);
    }

    /** La transition `$depuis -> $vers` est-elle permise ? */
    public static function transitionPermise(string $depuis, string $vers): bool
    {
        return in_array($vers, self::TRANSITIONS[$depuis] ?? [], true);
    }
}
