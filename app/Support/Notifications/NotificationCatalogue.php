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
 *   ne peut pas produire de ligne. Chaque canal porte desormais son `default` et
 *   sa `configurable`, lus par le resolver de preferences.
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

    /**
     * TASK-1377 — le canal EMAIL EXISTE, et AUCUNE cle ne l'autorise encore.
     *
     * Ce n'est pas une incoherence, c'est la tranche : P5 livre la mecanique de
     * livraison asynchrone — table d'etat, prise de travail atomique, worker,
     * preuve dans `email_logs` — sans l'activer sur quoi que ce soit.
     *
     * Activer EMAIL sur `loop.invitation` appartient a P6, et cette ligne le dit
     * plutot que de laisser croire a un oubli. Le catalogue continue de ne
     * declarer que ce qui EXISTE : la constante nomme un canal dont l'adaptateur
     * est desormais reel ; `ENTRIES` reste seul juge de qui a le droit de s'en
     * servir.
     */
    public const CHANNEL_EMAIL = 'email';

    public const LOOP_INVITATION = 'loop.invitation';

    /**
     * Le type d'objet de cette cle, expose comme constante.
     *
     * Le resolver de cible en a besoin pour brancher. Le lire ici plutot que de
     * reecrire la chaine ailleurs evite qu'une seconde verite s'installe.
     */
    public const OBJECT_LOOP_INVITATION = 'loop_invitation';

    /**
     * Le registre.
     *
     * `channels` est une CARTE, pas une liste : chaque canal autorise porte son
     * defaut et sa configurabilite au meme endroit. Les separer creerait deux
     * verites a tenir alignees, et c'est exactement ce que ce fichier existe
     * pour eviter.
     *
     * `configurable => false` n'est pas une simple absence de bouton. Le CDC est
     * explicite : « toute preference stockee contradictoire est IGNOREE par le
     * resolver — la securite ne depend pas seulement de l'absence de toggle UI ».
     * Une ligne ecrite a la main en base ne doit rien changer.
     *
     * @var array<string, array{category: string, object_type: string, channels: array<string, array{default: bool, configurable: bool}>}>
     */
    private const ENTRIES = [
        self::LOOP_INVITATION => [
            'category' => 'loop',
            'object_type' => self::OBJECT_LOOP_INVITATION,
            'channels' => [
                // CDC section 8 : « Invitation nominative : in-app OBLIGATOIRE ».
                // Une invitation s'adresse a quelqu'un personnellement et
                // appelle une reponse — on ne s'en desabonne pas.
                self::CHANNEL_IN_APP => ['default' => true, 'configurable' => false],
            ],
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
        return array_key_exists($channel, self::definition($notificationKey)['channels'] ?? []);
    }

    /** @return list<string> Les canaux autorises pour cette cle. */
    public static function channelsFor(string $notificationKey): array
    {
        return array_keys(self::definition($notificationKey)['channels'] ?? []);
    }

    /**
     * La valeur PAR DEFAUT d'un canal — l'unique source de verite.
     *
     * Aucune ligne n'est ecrite en base pour un membre qui n'a rien change : le
     * defaut vit ici, et les preferences stockees ne sont que des ECARTS. Une
     * base vide et un membre qui n'a jamais touche a ses reglages sont la meme
     * chose, ce qui est le cas de la quasi-totalite des gens.
     */
    public static function channelDefault(string $notificationKey, string $channel): ?bool
    {
        return self::definition($notificationKey)['channels'][$channel]['default'] ?? null;
    }

    /**
     * Ce canal peut-il etre regle par le membre ?
     *
     * `false` signifie OBLIGATOIRE : ni l'ecran ni une ecriture directe en base
     * ne peuvent le desactiver. Le resolver ignore toute preference contraire.
     */
    public static function channelIsConfigurable(string $notificationKey, string $channel): bool
    {
        return self::definition($notificationKey)['channels'][$channel]['configurable'] ?? false;
    }
}
