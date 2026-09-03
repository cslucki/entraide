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
 * correspondante — et les deux qui manquaient sont desormais arrivees :
 *
 * - `channels` a gagne `email` en T1378, l'adaptateur EMAIL existant depuis
 *   T1377 ;
 * - les champs de preference sont arrives avec l'ecran de preferences (T1375).
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
 * ## Le catalogue grandit evenement par evenement
 *
 * `loop.invitation` est l'exemple canonique du CDC et le premier producteur
 * reel a avoir ete branche — sur ses DEUX canaux. T1381 y ajoute les deux
 * reponses a une demande d'adhesion, sur IN_APP seulement. Rien ne se peuple
 * d'avance : une cle n'entre ici que le jour ou un producteur reel l'emet.
 *
 * ## `in_app` obligatoire n'est pas une regle du canal
 *
 * Trois cles le declarent aujourd'hui non reglable, mais chacune pour SA raison
 * — l'invitation par le CDC section 8, les deux decisions parce qu'aucun autre
 * canal ne pourrait porter la reponse. Ne pas lire cette repetition comme une
 * propriete du canal : une future cle IN_APP pourra tres bien etre reglable.
 *
 * ## Cette entree est le POINT DE BASCULE du cutover
 *
 * C'est la presence de `email` dans `channels` — et elle seule — qui fait que
 * le planificateur cree une livraison EMAIL et que le mailer legacy s'efface
 * pour les membres de l'Organization. La retirer suffit a revenir en arriere,
 * sans toucher a une ligne de code ailleurs.
 */
final class NotificationCatalogue
{
    public const CHANNEL_IN_APP = 'in_app';

    /**
     * Le canal EMAIL. Adaptateur livre en T1377, ACTIVE en T1378.
     *
     * T1377 avait livre toute la mecanique de livraison asynchrone — table
     * d'etat, prise de travail atomique, worker, preuve dans `email_logs` — sans
     * l'activer sur quoi que ce soit. T1378 l'active sur `loop.invitation`, et
     * `ENTRIES` reste seul juge de qui a le droit de s'en servir.
     */
    public const CHANNEL_EMAIL = 'email';

    public const LOOP_INVITATION = 'loop.invitation';

    /**
     * TASK-1381 — la reponse a une demande d'adhesion.
     *
     * DEUX cles, pas une avec un statut. Une notification designe un FAIT, et
     * « accepte » et « refuse » sont deux faits distincts : ils n'ont ni le meme
     * libelle, ni la meme suite, et un membre doit pouvoir les distinguer dans
     * son Centre sans ouvrir quoi que ce soit.
     *
     * Les fondre en `loop.join_request.decided` obligerait le rendu a relire
     * l'objet metier pour savoir quoi ecrire — donc a dependre d'un etat qui a
     * pu changer depuis. Le fait, lui, ne change pas.
     */
    public const LOOP_JOIN_REQUEST_ACCEPTED = 'loop.join_request.accepted';

    public const LOOP_JOIN_REQUEST_REJECTED = 'loop.join_request.rejected';

    /**
     * Le type d'objet de cette cle, expose comme constante.
     *
     * Le resolver de cible en a besoin pour brancher. Le lire ici plutot que de
     * reecrire la chaine ailleurs evite qu'une seconde verite s'installe.
     */
    public const OBJECT_LOOP_INVITATION = 'loop_invitation';

    /**
     * Les deux cles de decision partagent CE type d'objet.
     *
     * C'est voulu : elles parlent de la meme demande. Ce qui les separe est le
     * FAIT, porte par `notification_key`, pas l'objet dont elles parlent.
     */
    public const OBJECT_LOOP_JOIN_REQUEST = 'loop_join_request';

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

                // TASK-1378 — EMAIL active, et CONFIGURABLE.
                //
                // Actif par defaut parce qu'une invitation appelle une reponse
                // et qu'on ne peut pas supposer que la personne reviendra dans
                // l'application d'elle-meme. Configurable parce que, cette
                // fois, se desabonner a un sens : le fait reste visible dans le
                // Centre, et couper l'email ne fait donc rien perdre.
                //
                // C'est cette ligne — et elle seule — qui realise le cutover :
                // le planificateur cree desormais une livraison EMAIL, et le
                // mailer legacy s'efface pour les membres de l'Organization.
                // La retirer suffit a revenir en arriere.
                self::CHANNEL_EMAIL => ['default' => true, 'configurable' => true],
            ],
        ],

        // TASK-1381 — la reponse a une demande faite PAR le destinataire.
        //
        // ## IN_APP est OBLIGATOIRE, et pas par symetrie avec l'invitation
        //
        // Le premier reflexe etait de le rendre reglable : la personne connait
        // deja sa demarche, elle pourrait choisir d'aller voir plutot que d'etre
        // prevenue. La mesure a montre ce que cela coute reellement.
        //
        // Aucun canal EMAIL n'existe sur ces cles (voir plus bas). Le Centre est
        // donc l'UNIQUE endroit ou la reponse peut arriver, et couper le canal
        // n'empeche pas la notification d'etre lue : il l'empeche d'EXISTER.
        // Le membre ne perdrait pas un rappel, il perdrait la reponse a une
        // question qu'il a posee lui-meme, sans aucun moyen de la retrouver.
        //
        // C'est la difference avec EMAIL sur l'invitation, ou se desabonner ne
        // fait rien perdre parce que le fait reste visible dans le Centre.
        //
        // Effet de bord mesure, et c'est ce qui a tranche : rendre `in_app`
        // reglable ici faisait rougir trois tests de TASK-1375. Ils affirment
        // qu'aucun ecart n'est jamais stocke sur `in_app` — vrai tant que ce
        // canal n'etait obligatoire que sur l'unique cle existante. Le proxy
        // « in_app = obligatoire » aurait cesse d'etre vrai, et il aurait fallu
        // reecrire des tests existants pour accommoder un choix qui n'etait de
        // toute facon pas le bon.
        //
        // ## Aucun canal EMAIL dans cette tranche
        //
        // L'autorite du contenu des emails est `SystemEmailTemplate`, filtre par
        // Organization et locale. Aucun modele n'existe pour ces deux faits, et
        // en fabriquer un en fichier de langue installerait une seconde autorite
        // metier — exactement ce qui est interdit. EMAIL reste donc hors
        // catalogue ici : l'absence de la ligne suffit a ce que rien ne parte,
        // et l'ajouter plus tard n'exigera aucune autre modification.
        self::LOOP_JOIN_REQUEST_ACCEPTED => [
            'category' => 'loop',
            'object_type' => self::OBJECT_LOOP_JOIN_REQUEST,
            'channels' => [
                self::CHANNEL_IN_APP => ['default' => true, 'configurable' => false],
            ],
        ],

        self::LOOP_JOIN_REQUEST_REJECTED => [
            'category' => 'loop',
            'object_type' => self::OBJECT_LOOP_JOIN_REQUEST,
            'channels' => [
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
