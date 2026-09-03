<?php

namespace App\Support\Notifications;

/**
 * TASK-1372 — le catalogue des notifications que BouclePro sait emettre.
 *
 * ## Fail-closed : une cle absente d'ici n'existe pas
 *
 * Il n'y a pas de cle « libre ». `NotificationEmitter` refuse tout ce que ce
 * fichier ne declare pas, et le refus est une exception, pas un silence. Meme
 * doctrine que `CapabilityRegistry` et `ProductSurfaceManifest` (T1370) : le
 * registre est ECRIT A LA MAIN, jamais derive d'un scan.
 *
 * ## Le catalogue ne declare que ce qui EXISTE
 *
 * C'est la lecon de T1370 transposee ici. Un catalogue qui annoncerait un canal
 * EMAIL sans adaptateur EMAIL, ou une preference configurable sans ecran de
 * preferences, mentirait — et c'est precisement lui qui est cense faire
 * autorite. Chaque tranche ajoute donc ses champs quand elle livre la capacite
 * correspondante :
 *
 * - `channels` gagnera `email` quand l'adaptateur EMAIL existera ;
 * - les champs de preference (defaut, configurable, obligatoire) arriveront
 *   avec l'ecran de preferences, pas avant.
 *
 * Etat exact de ce que chaque champ vaut aujourd'hui — parce qu'un registre qui
 * se surestime est precisement le defaut que T1370 a corrige ailleurs :
 *
 * - `object_type` : **verifie a l'ecriture**, sur toutes les portes.
 * - `channels` : **verifie a l'ecriture** — une cle qui n'autorise pas `in_app`
 *   ne peut pas produire de ligne. La garde ne mordra vraiment qu'avec une cle
 *   EMAIL seulement, mais elle existe et elle est branchee.
 * - `category` : **declare, pas encore consomme**. Il servira au regroupement du
 *   Centre. Tant qu'aucun code ne le lit, cette ligne le dit.
 *
 * ## Une seule cle, et c'est voulu
 *
 * `loop.invitation` est l'exemple canonique du CDC et le premier producteur
 * reel a brancher. Le catalogue grandit evenement par evenement ; il ne se
 * peuple pas d'avance.
 */
final class NotificationCatalogue
{
    public const CHANNEL_IN_APP = 'in_app';

    public const LOOP_INVITATION = 'loop.invitation';

    /**
     * Le type d'objet de cette cle, expose comme constante.
     *
     * Le resolver de cible en a besoin pour brancher. Le lire ici plutot que de
     * reecrire la chaine ailleurs evite qu'une seconde verite s'installe.
     */
    public const OBJECT_LOOP_INVITATION = 'loop_invitation';

    /**
     * @var array<string, array{category: string, object_type: string, channels: list<string>}>
     */
    private const ENTRIES = [
        self::LOOP_INVITATION => [
            'category' => 'loop',
            'object_type' => self::OBJECT_LOOP_INVITATION,
            'channels' => [self::CHANNEL_IN_APP],
        ],
    ];

    public static function has(string $notificationKey): bool
    {
        return array_key_exists($notificationKey, self::ENTRIES);
    }

    /**
     * @return array{category: string, object_type: string, channels: list<string>}|null
     */
    public static function definition(string $notificationKey): ?array
    {
        return self::ENTRIES[$notificationKey] ?? null;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::ENTRIES);
    }

    /**
     * Le type d'objet metier que cette cle a le droit de referencer.
     *
     * C'est un CONTRAT verifie a l'emission : une notification `loop.invitation`
     * ne peut pas pointer vers un document. Sans cette garde, `object_type`
     * serait un champ libre, et le rendu ne saurait plus quoi resoudre.
     *
     * La valeur est un SLUG stable (`loop_invitation`), pas un nom de classe.
     * Un FQCN stocke en base transforme le moindre deplacement de namespace en
     * migration de donnees — et le depot n'impose aucune morph map qui
     * protegerait de cela.
     */
    public static function objectTypeFor(string $notificationKey): ?string
    {
        return self::definition($notificationKey)['object_type'] ?? null;
    }

    public static function allowsChannel(string $notificationKey, string $channel): bool
    {
        return in_array($channel, self::definition($notificationKey)['channels'] ?? [], true);
    }
}
