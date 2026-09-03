<?php

namespace App\Support\Notifications;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * TASK-1375 — les invariants d'un reglage de notification.
 *
 * Meme patron qu'en T1372 : les regles vivent ici, et le modele les applique sur
 * `creating` ET `updating`. Peu importe la porte — un controleur, un
 * `create()` direct, un futur service : la regle est la meme parce qu'il n'y en
 * a qu'une.
 *
 * ## Ce qu'un reglage ne peut pas etre
 *
 * 1. Une cle absente du catalogue. Un reglage qui ne gouverne rien serait un
 *    mensonge affiche a l'ecran.
 * 2. Un canal que la cle n'autorise pas. Regler l'email d'une notification qui
 *    n'en envoie pas revient au meme mensonge.
 * 3. **Un ecart sur un canal OBLIGATOIRE.** C'est le point qui compte : le CDC
 *    demande que la securite ne repose pas sur l'absence de bouton. On refuse
 *    donc l'ecriture — et, ceinture et bretelles, le resolver ignore de toute
 *    facon une ligne contradictoire qui aurait ete inseree autrement.
 *
 * Refuser a l'ecriture ET ignorer a la lecture n'est pas redondant : la
 * premiere garde protege le chemin applicatif, la seconde protege des lignes
 * arrivees par un import, une migration, ou une base heritee.
 */
final class NotificationPreferenceInvariants
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function assert(array $attributes): void
    {
        $notificationKey = (string) ($attributes['notification_key'] ?? '');
        $channel = (string) ($attributes['channel'] ?? '');

        if (! NotificationCatalogue::has($notificationKey)) {
            throw new InvalidArgumentException(
                "Notification key [{$notificationKey}] is not declared in NotificationCatalogue."
            );
        }

        if (! NotificationCatalogue::allowsChannel($notificationKey, $channel)) {
            throw new InvalidArgumentException(
                "Notification key [{$notificationKey}] does not allow the [{$channel}] channel."
            );
        }

        if (! NotificationCatalogue::channelIsConfigurable($notificationKey, $channel)) {
            throw new InvalidArgumentException(
                "Channel [{$channel}] of [{$notificationKey}] is mandatory and cannot be overridden."
            );
        }

        self::assertUuid($attributes['user_id'] ?? null);
    }

    /**
     * Le membre concerne, verifie comme un UUID avant toute requete.
     *
     * Meme raison qu'en T1372 : sous PostgreSQL la colonne est un `uuid` natif,
     * et une chaine qui n'en est pas un y leve `22P02` — une erreur serveur — la
     * ou SQLite se contenterait de ne rien trouver.
     */
    private static function assertUuid(mixed $userId): void
    {
        if (! is_string($userId) || ! Str::isUuid($userId)) {
            throw new InvalidArgumentException('Notification preference user_id must be a UUID.');
        }
    }
}
