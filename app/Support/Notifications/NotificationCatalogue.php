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
 * T1744 n'ajoute AUCUNE cle : elle ouvre le canal `web_push` sur quatre cles
 * qui existaient deja, et c'est tout ce que l'activation du Push demandait —
 * le transport etait livre depuis T1740 et attendait cette decision produit.
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

    /**
     * Le canal WEB PUSH. Abonnements livres en T1739, ENVOI livre en T1740.
     *
     * TROISIEME canal de la MEME autorite, jamais un systeme parallele : un
     * evenement metier reste UNE notification, et `in_app`, `email` et
     * `web_push` en sont trois modes de livraison que le catalogue et les
     * preferences gouvernent de la meme facon (CDC section 6.1).
     *
     * Il est declare ici APRES la correction du defaut de
     * `NotificationDeliveryPlanner::plan()` — qui dispatchait le job email
     * dans la boucle sur les canaux — parce que l'ajouter avant aurait mis en
     * file un job EMAIL pour chaque notification eligible au Push.
     *
     * Un Push n'est jamais OBLIGATOIRE : il sort de l'application et atterrit
     * sur un ecran verrouille. Toute cle qui l'autorise le declare donc
     * `configurable => true`.
     *
     * ## `default => true`, et pourquoi ce n'est PAS « allumer a la place de
     * quelqu'un » (arbitrage Cyril, 03/11)
     *
     * T1744 avait livre ces cles en `default => false`, au motif qu'un Push
     * s'affiche sur un ecran verrouille. L'argument etait juste sur la
     * CONSEQUENCE et faux sur la PORTE : le defaut du catalogue ne peut
     * reveiller personne a lui seul.
     *
     * La vraie barriere est ailleurs, et elle est double : il faut un
     * abonnement d'appareil — un geste explicite, revocable d'un bouton — et
     * la permission systeme accordee au navigateur. Sans appareil abonne, le
     * livreur conclut `no_device_subscribed` quel que soit le defaut. Un
     * compte sans telephone ne recoit donc rien, et c'est le cas de la
     * quasi-totalite des gens.
     *
     * Ce que `default => false` produisait reellement : une personne qui
     * venait d'activer les notifications sur son appareil — donc qui avait
     * demande a etre prevenue — ne recevait RIEN, et devait deviner qu'il
     * restait des cases a cocher ailleurs dans la page. La recette du 02/11
     * s'y est cassee deux fois.
     *
     * Le panneau d'appareil promet « Recevez une notification lorsqu'une
     * personne a besoin de vous ». `default => true` tient cette promesse ;
     * les cases servent alors a REDUIRE le bruit, pas a autoriser le canal.
     */
    public const CHANNEL_WEB_PUSH = 'web_push';

    /**
     * TASK-1746 (CDC §7A) — l'INTENTION d'un evenement, et pourquoi ce n'est
     * pas `category`.
     *
     * `category` existait deja et ne prend que deux valeurs : `loop` et
     * `blog`. Ce sont des DOMAINES D'OBJET. En deriver les sections de l'ecran
     * de reglages donnerait « Boucles » et « Blog » — un classement par
     * technique, alors que le CDC §7 demande que la personne « comprenne
     * pourquoi elle est prevenue ».
     *
     * Et le domaine se degrade des le §8.1 : une demande d'aide serait rangee
     * dans `loop`, a cote d'une invitation, alors que les deux n'ont rien de
     * commun pour qui les recoit. L'une appelle une reponse, l'autre informe.
     *
     * L'intention repond donc a une autre question — « qu'est-ce que cela me
     * demande ? » — et c'est elle qui gouverne le regroupement. `category`
     * reste intact dans son role.
     *
     * Ce qui m'est ADRESSE et attend quelque chose de moi.
     */
    public const INTENTION_SOLLICITATION_DIRECTE = 'sollicitation_directe';

    /** Ce qui me CONCERNE sans rien exiger : on m'informe. */
    public const INTENTION_CHANGEMENTS = 'changements';

    /**
     * Ce qui m'est propose parce que je PEUX AIDER (CDC §9.2).
     *
     * Declaree des maintenant, et volontairement sans aucune cle : la decision
     * MASTER du 03/11 rend la sollicitation pertinente CONFIGURABLE, elle aura
     * donc besoin d'une section. La provisionner ici evite de rouvrir l'ecran
     * au moment du §9.2 — et une section sans cle eligible ne s'affiche pas,
     * donc elle ne coute rien a personne en attendant.
     */
    public const INTENTION_SOLLICITATION_PERTINENTE = 'sollicitation_pertinente';

    /**
     * L'ORDRE d'affichage des sections, et il porte une decision produit.
     *
     * Ce qui attend une reponse vient en premier : c'est ce qu'on vient
     * regler quand on arrive ici parce qu'on recoit trop. Les changements
     * ensuite, les propositions en dernier.
     *
     * @var list<string>
     */
    public const INTENTIONS = [
        self::INTENTION_SOLLICITATION_DIRECTE,
        self::INTENTION_CHANGEMENTS,
        self::INTENTION_SOLLICITATION_PERTINENTE,
    ];

    public const LOOP_INVITATION = 'loop.invitation';

    /**
     * TASK-1719 — « on vous a AJOUTE », qui n'est pas « on vous a invite ».
     *
     * Deux faits distincts, donc deux cles — meme raison qu'en TASK-1381 pour
     * les decisions d'adhesion. Une invitation appelle une reponse ; un ajout
     * direct ne demande rien : la personne EST membre quand elle lit le
     * message. Les fondre obligerait le rendu a relire l'objet metier pour
     * savoir quoi ecrire, donc a dependre d'un etat qui a pu changer depuis.
     *
     * C'est le chemin « Ajouter des membres » de la Card Membres, mesure muet :
     * il creait l'appartenance et RIEN d'autre — ni invitation, ni
     * notification, ni email.
     */
    public const LOOP_MEMBER_ADDED = 'loop.member_added';

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
    /**
     * TASK-1761 point 1 — QUELQU'UN DEMANDE a rejoindre votre Boucle.
     *
     * Les deux cles voisines portent la DECISION, et elles vont au demandeur.
     * Celle-ci porte la DEMANDE, et elle va au PROPRIETAIRE — c'est le seul
     * sens dans lequel ce catalogue ne savait rien dire.
     *
     * Avant cette TASK, `LoopService::createJoinRequest()` ecrivait la ligne et
     * n'emettait RIEN : personne n'apprenait qu'on attendait une reponse.
     */
    /**
     * TASK-1772 — on m'a nommement appele dans une ChatLoop.
     *
     * Le nom dit le FAIT (on m'a mentionne), jamais le support (un message).
     * Ce n'est pas une coquetterie : `TASK1744WebPushBusinessEventsTest`
     * interdit les sous-chaines `chatloop` et `message` dans toute cle qui
     * pousse, parce que la §6.2 du CDC notifications combat la diffusion de
     * TOUT message a TOUS les membres. Une mention est l'inverse de ce
     * bruit-la — elle vise une personne designee — mais la garde teste une
     * sous-chaine, pas une intention. `loop.mention.created` passe telle
     * quelle et suit la forme des voisines.
     */
    public const LOOP_MENTION_CREATED = 'loop.mention.created';

    public const LOOP_JOIN_REQUEST_CREATED = 'loop.join_request.created';

    public const LOOP_JOIN_REQUEST_ACCEPTED = 'loop.join_request.accepted';

    public const LOOP_JOIN_REQUEST_REJECTED = 'loop.join_request.rejected';

    /**
     * TASK-1697 — « quand un utilisateur fait un commentaire, il faut que
     * l'auteur de l'article recoive une notification » (Cyril).
     */
    public const BLOG_COMMENT_CREATED = 'blog.comment.created';

    /**
     * TASK-1702 — on vient de vous ajouter comme CO-AUTEUR d'un article.
     *
     * Cyril : « quand on ajoute un auteur X en co-ecriture, il doit recevoir
     * une notification dans l'application pour l'informer de cela. »
     */
    /**
     * TASK-1747 (CDC §8.1) — une demande d'aide vient d'etre publiee dans une
     * Boucle dont je suis membre.
     *
     * PREMIER producteur du §8, et le premier evenement COLLECTIF du
     * catalogue : les six cles precedentes s'adressaient a UNE personne
     * designee — on l'invite, on l'ajoute, on accepte sa demande, on la nomme
     * co-auteur. Celle-ci previent TOUS les membres d'une Boucle.
     *
     * ## Ses defauts ont ete RENVERSES le 07/11 (TASK-1775)
     *
     * Etat du 03/11 (decision MASTER) : in-app CONFIGURABLE, Web Push declare
     * mais ETEINT — pour ne pas transformer le premier producteur collectif en
     * sollicitation de masse sur ecran verrouille.
     *
     * Etat depuis le 07/11 (arbitrage Cyril, apres recette de l'ecran
     * « Mes Boucles ») : **in-app OBLIGATOIRE**, **Web Push et EMAIL ACTIFS
     * par defaut**. Ce qui a change entre les deux, et qui le justifie : la
     * sourdine PAR BOUCLE (`LoopNotificationPreference`) n'existait pas le
     * 03/11. C'est elle qui porte desormais le calme, canal par canal et
     * Boucle par Boucle, a la place d'un reglage global sur l'in-app.
     *
     * Les valeurs qui font autorite sont dans `ENTRIES`, pas ici.
     */
    public const LOOP_HELP_REQUEST_CREATED = 'loop.help_request.created';

    /**
     * TASK-1802 (demande Cyril) — un Evenement vient d'etre propose dans une
     * Boucle.
     *
     * Meme forme que `LOOP_HELP_REQUEST_CREATED` juste au-dessus, et pour la
     * meme raison : un fait COLLECTIF, adresse a TOUS les membres actifs de la
     * Boucle (sauf son auteur), pas a une personne designee. « Par defaut, les
     * 3 options seront activees » (demande explicite) : in-app OBLIGATOIRE,
     * email et Web Push actifs par defaut mais debrayables — memes valeurs que
     * la demande d'aide, ajoutee ici a `loopScopeFor()` pour que la sourdine
     * PAR BOUCLE (`LoopNotificationPreference`) s'applique aussi a ce fait.
     */
    public const LOOP_EVENT_CREATED = 'loop.event.created';

    public const BLOG_CO_AUTHOR_ADDED = 'blog.co_author.added';

    /**
     * TASK-1751 — quelqu'un repond a votre annonce.
     *
     * ## UNE cle, et pas deux
     *
     * Les deux boutons — « Demander cette aide » sur un service, « Proposer
     * mon aide » sur une demande — passent par le MEME `store()`, et les roles
     * s'y inversent. On aurait donc pu declarer deux cles.
     *
     * Le catalogue dit qu'une notification designe un FAIT. Vu du
     * destinataire, le fait ne change pas : quelqu'un repond a l'annonce que
     * j'ai postee, et je dois decider. `$transaction->subject` rend deja le
     * titre du service OU de la demande sans que l'appelant ait a savoir
     * lequel. Deux cles auraient duplique l'entree, la migration, le seeder et
     * les quatre libelles pour une nuance de texte.
     */
    public const TRANSACTION_PROPOSAL_CREATED = 'transaction.proposal_created';

    /**
     * TASK-1786 — on a commente votre proposition de service ou votre demande
     * d'aide.
     *
     * ## UNE cle pour les DEUX types d'annonce, et c'est deja la doctrine
     *
     * `TASK_1751` a tranche la meme question juste au-dessus, pour le meme
     * domaine : « deux cles auraient duplique l'entree, la migration, le seeder
     * et les quatre libelles pour une nuance de texte ». Le raisonnement vaut
     * mot pour mot ici.
     *
     * Vu du destinataire, le fait ne change pas : quelqu'un a commente l'annonce
     * que j'ai publiee. Que ce soit une proposition ou une demande est une
     * nuance de LIBELLE, resolue a l'affichage — pas deux faits distincts.
     *
     * Et l'effet de bord compte autant : une cle unique donne **une seule
     * entree** dans « Reglages des notifications », exactement ce que le CDC
     * demande — « Commentaires sur mes propositions et demandes d'aide ». Deux
     * cles auraient affiche deux blocs de trois cases pour un seul reglage
     * percu.
     *
     * ## Distincte des commentaires de BLOG, et c'est exige
     *
     * Le CDC le demande explicitement : « creer un evenement metier explicite,
     * distinct des commentaires de Blog », et « les preferences sont
     * independantes des commentaires de Blog ». Couper l'un ne coupe donc pas
     * l'autre.
     */
    public const ENTRAIDE_COMMENT_CREATED = 'entraide.comment.created';

    /**
     * TASK-1810 (demande Cyril, 12/11) — on a commente un item du Flux.
     *
     * ## Le destinataire est TOUJOURS le meme : l'« emetteur ou la personne
     * evoquee » par l'item
     *
     * Demande explicite : « sur les items du flux (annonces ou evenements
     * automatiques), on a toujours un emetteur ou quelqu'un qui est evoque,
     * donc c'est cette personne qu'il faut notifier ». Mesure dans
     * `FeedEventEmitter::emettre()` : `feed_posts.user_id` vaut TOUJOURS
     * l'acteur de l'evenement pour les sept types automatiques, et l'auteur
     * pour une annonce manuelle. Une seule regle de ciblage suffit donc :
     * `$post->user`, jamais une relecture au cas par cas de chaque type.
     *
     * ## UNE cle pour les deux familles d'item (automatique et manuelle)
     *
     * Meme doctrine que `ENTRAIDE_COMMENT_CREATED` juste au-dessus : vu du
     * destinataire, le fait ne change pas (on a commente mon item), seul le
     * CONTEXTE affiche varie — et il est resolu a l'envoi, pas fige dans une
     * seconde cle.
     */
    public const FEED_POST_COMMENT_CREATED = 'feed.post_comment.created';

    /**
     * TASK-1810 — on a reagi a un item du Flux.
     *
     * « Si la personne fait juste une reaction, dans ce cas la notification
     * est juste dans l'application pas besoin d'envoyer un email » (demande
     * explicite) — PREMIERE cle du catalogue dont `channels` ne declare QUE
     * `in_app`. Aucune branche n'est donc necessaire dans
     * `NotificationEmailDeliverer::rendreDepuisGabarit()` : le planificateur
     * ne cree jamais de livraison EMAIL pour une cle qui ne declare pas ce
     * canal (`NotificationDeliveryPlanner::JOB_PAR_CANAL`), donc
     * `deliver()` n'est jamais invoque pour cette cle.
     */
    public const FEED_POST_REACTION_CREATED = 'feed.post_reaction.created';

    /**
     * Le type d'objet de cette cle, expose comme constante.
     *
     * Le resolver de cible en a besoin pour brancher. Le lire ici plutot que de
     * reecrire la chaine ailleurs evite qu'une seconde verite s'installe.
     */
    public const OBJECT_LOOP_INVITATION = 'loop_invitation';

    /**
     * TASK-1719 — l'objet d'un ajout direct est la BOUCLE, pas une invitation.
     *
     * Il n'existe aucune ligne `loop_invitations` sur ce chemin : pointer vers
     * un objet qui n'a jamais ete cree rendrait la notification inexploitable
     * des qu'on voudrait la relier a quelque chose.
     */
    public const OBJECT_LOOP = 'loop';

    /**
     * Les deux cles de decision partagent CE type d'objet.
     *
     * C'est voulu : elles parlent de la meme demande. Ce qui les separe est le
     * FAIT, porte par `notification_key`, pas l'objet dont elles parlent.
     */
    public const OBJECT_LOOP_JOIN_REQUEST = 'loop_join_request';

    /**
     * TASK-1772 — le message lui-meme, et non la Boucle qui le contient.
     *
     * Arbitrage Cyril : la notification doit mener a LA PHRASE ou l'on a ete
     * nomme. Dans une Boucle a cinq cents messages, « on vous a mentionne
     * ici » sans dire OU oblige a chercher.
     *
     * Un type DISTINCT de `OBJECT_LOOP`, et c'est ce qui evite un defaut
     * silencieux : `NotificationCenterController` unit deux cartes de sujets
     * avec `+`, et `+` garde la valeur de GAUCHE. Reutiliser `OBJECT_LOOP`
     * aurait fait ignorer en silence la carte qui nomme l'auteur.
     */
    public const OBJECT_LOOP_MESSAGE = 'loop_message';

    /**
     * TASK-1697 — un commentaire depose sur un article de blog.
     *
     * L'objet designe est le COMMENTAIRE, pas l'article : c'est lui qui
     * disparait si on le supprime, et le resolver doit alors rendre `null`
     * plutot que d'envoyer vers un fil ou il n'y a plus rien a lire.
     */
    public const OBJECT_BLOG_COMMENT = 'blog_comment';

    /**
     * TASK-1702 — l'ARTICLE lui-meme.
     *
     * Distinct de `OBJECT_BLOG_COMMENT`, et la difference porte : un
     * commentaire mene a l'article PUBLIE, une co-ecriture mene a son
     * EDITEUR. Un article co-ecrit est le plus souvent un brouillon — l'envoyer
     * sur la page publique ne menerait nulle part.
     */
    public const OBJECT_BLOG_POST = 'blog_post';

    /**
     * TASK-1751 — l'ECHANGE lui-meme.
     *
     * La cible est la CONVERSATION (`messages.show`), et non une fiche
     * d'annonce : c'est la que le destinataire decide, et les boutons
     * Accepter / Refuser y sont deja rendus pour une Transaction `pending`.
     * L'envoyer sur le service ou la demande lui ferait relire son propre
     * texte sans rien pouvoir faire.
     */
    public const OBJECT_TRANSACTION = 'transaction';

    /**
     * TASK-1786 — un commentaire depose sous une annonce d'entraide.
     *
     * L'objet designe est le COMMENTAIRE, pas l'annonce — meme raison que
     * `OBJECT_BLOG_COMMENT` : c'est lui qui disparait si on le supprime, et le
     * resolver doit alors rendre `null` plutot que d'envoyer vers un fil ou il
     * n'y a plus rien a lire.
     *
     * C'est aussi lui qui porte `commentable_type` : le resolver y lit s'il faut
     * mener a `services.show` ou a `requests.show`, ce qui est precisement ce
     * qui permet a UNE cle de couvrir les deux types d'annonce.
     */
    public const OBJECT_ENTRAIDE_COMMENT = 'entraide_comment';

    /**
     * TASK-1810 — le commentaire depose sous un item du Flux.
     *
     * L'objet designe est le COMMENTAIRE, pas l'item — meme raison que
     * `OBJECT_BLOG_COMMENT`/`OBJECT_ENTRAIDE_COMMENT` : c'est lui qui
     * disparait si on le retire, et le resolver doit alors rendre `null`.
     */
    public const OBJECT_FEED_POST_COMMENT = 'feed_post_comment';

    /**
     * TASK-1810 — l'item du Flux lui-meme, cible d'une REACTION.
     *
     * Distinct de `OBJECT_FEED_POST_COMMENT` : une reaction ne cree aucune
     * ligne perenne (`toggleReaction()` supprime la ligne `Reaction` sur un
     * second clic du meme type) — l'objet notifiable est donc l'ITEM, qui
     * survit, jamais la reaction elle-meme.
     */
    public const OBJECT_FEED_POST = 'feed_post';

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
            'intention' => self::INTENTION_SOLLICITATION_DIRECTE,
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

                // TASK-1744 — WEB PUSH active, et c'est l'evenement de recette
                // du CDC section 6.5 : « une invitation autorisee provoque une
                // ligne in-app existante ET une livraison Push ».
                //
                // `default => true` depuis l'arbitrage Cyril du 03/11 : voir le
                // docblock de CHANNEL_WEB_PUSH. Le defaut ne reveille personne
                // a lui seul — il faut encore un appareil abonne ET la
                // permission systeme, deux gestes explicites qui ne peuvent pas
                // etre faits a sa place. Ce reglage sert donc a REDUIRE le
                // bruit, pas a autoriser le canal.
                self::CHANNEL_WEB_PUSH => ['default' => true, 'configurable' => true],
            ],
        ],

        // TASK-1719 — l'ajout direct dans une Boucle.
        self::LOOP_MEMBER_ADDED => [
            'category' => 'loop',
            'intention' => self::INTENTION_CHANGEMENTS,
            'object_type' => self::OBJECT_LOOP,
            'channels' => [
                // IN_APP obligatoire, comme l'invitation : on ne se desabonne
                // pas d'apprendre qu'on vient d'etre mis quelque part.
                self::CHANNEL_IN_APP => ['default' => true, 'configurable' => false],

                // EMAIL actif par defaut, et configurable. Actif parce que la
                // personne n'a rien demande : rien ne garantit qu'elle
                // reviendra d'elle-meme voir une Boucle dont elle ignore
                // l'existence. Configurable parce que le fait reste visible
                // dans le Centre, donc couper l'email ne fait rien perdre.
                self::CHANNEL_EMAIL => ['default' => true, 'configurable' => true],

                // TASK-1744 — CDC 6.2 : « Oui, si opt-in. Pas de nom de Boucle
                // privee ». Le titre de Push ne nomme donc aucune Boucle, la
                // ou le libelle du Centre le fait depuis T1725 : un Push
                // s'affiche sur un ecran verrouille, le Centre non.
                self::CHANNEL_WEB_PUSH => ['default' => true, 'configurable' => true],
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
        // A l'origine, aucun canal EMAIL n'existait sur ces cles : le Centre
        // etait l'UNIQUE endroit ou la reponse pouvait arriver, et couper le
        // canal n'empechait pas la notification d'etre lue, il l'empechait
        // d'EXISTER. Le membre n'aurait pas perdu un rappel, il aurait perdu la
        // reponse a une question posee par lui-meme, sans moyen de la retrouver.
        //
        // TASK-1725 ajoute EMAIL sur « accepte » (voir plus bas). L'argument
        // ci-dessus n'en est pas affaibli : il porte sur IN_APP, qui reste le
        // support DURABLE du fait. L'email previent, le Centre conserve.
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
        // ## EMAIL sur « accepte », rien sur « refuse » (TASK-1725)
        //
        // La tranche TASK-1381 laissait les deux cles sans canal EMAIL, pour une
        // raison qui etait juste a ce moment-la : l'autorite du contenu des
        // emails est `SystemEmailTemplate`, filtre par Organization et locale, et
        // aucun gabarit n'existait pour ces faits. En fabriquer un en fichier de
        // langue aurait installe une seconde autorite metier.
        //
        // TASK-1725 (demande Cyril, 29/10) leve exactement cet obstacle, et de la
        // facon prevue : un gabarit `loop_join_request_accepted` est POSE en base
        // — migration pour les installations existantes, catalogue du seeder pour
        // les bases fraiches. L'autorite reste donc unique et administrable.
        // Comme annonce ici en TASK-1381, l'ajout n'a demande aucune autre
        // modification du catalogue que la ligne ci-dessous.
        //
        // Actif par defaut : la personne a fait une demande et attend une reponse
        // qu'elle ne peut pas deviner — rien ne garantit qu'elle reviendra
        // regarder le Centre. Configurable : le fait reste dans le Centre, donc
        // couper l'email ne fait rien perdre.
        //
        // ## « Refuse » n'en recoit PAS, et ce n'est pas un oubli
        //
        // Perimetre demande : « Quand le proprietaire accepte que l'utilisateur
        // rejoigne : il faut envoyer un email ». Un refus n'ouvre aucune porte et
        // n'appelle aucun geste ; le pousser dans la boite de quelqu'un ajoute une
        // peine sans rien lui permettre. Le fait reste visible dans le Centre pour
        // qui va voir. A rouvrir si MASTER le demande : une ligne ici, un gabarit,
        // rien de plus.
        self::LOOP_MENTION_CREATED => [
            'category' => 'loop',

            // « On me sollicite DIRECTEMENT » : quelqu'un m'a designe par mon
            // nom et attend que je lise. C'est la rubrique que la demande de
            // Cyril nomme explicitement — « Quand on me sollicite ».
            'intention' => self::INTENTION_SOLLICITATION_DIRECTE,

            'object_type' => self::OBJECT_LOOP_MESSAGE,
            'channels' => [
                // NON configurable, arbitrage Cyril du 06/11.
                //
                // On ne se desabonne pas d'apprendre qu'on a ete nommement
                // appele : la couper, c'est ne jamais savoir qu'on vous parle.
                // Meme raison que l'invitation et la proposition d'echange.
                // La ligne RESTE affichee a l'ecran, verrouillee, avec son
                // `preferences_mandatory_hint` : le membre a le droit de
                // savoir ce qu'il recevra.
                self::CHANNEL_IN_APP => ['default' => true, 'configurable' => false],

                // Configurable : le fait reste lisible dans le Centre, donc
                // couper l'email ne fait RIEN perdre. C'est le reglage que la
                // demande de Cyril designe.
                self::CHANNEL_EMAIL => ['default' => true, 'configurable' => true],

                // « Notification application (et donc PWA) ». Aligne sur
                // `LOOP_INVITATION` : un evenement PERSONNEL, pas collectif.
                // Le defaut ne reveille personne a lui seul — il faut encore
                // un appareil abonne ET la permission systeme. Jamais
                // obligatoire : `test_le_push_reste_toujours_REGLABLE`.
                self::CHANNEL_WEB_PUSH => ['default' => true, 'configurable' => true],
            ],
        ],

        self::LOOP_JOIN_REQUEST_CREATED => [
            'category' => 'loop',

            // « On me sollicite » : quelqu'un s'adresse au proprietaire et
            // attend une reponse de lui. Ce n'est ni un changement subi, ni une
            // information de fond.
            'intention' => self::INTENTION_SOLLICITATION_PERTINENTE,

            'object_type' => self::OBJECT_LOOP_JOIN_REQUEST,
            'channels' => [
                // NON configurable, et c'est un choix.
                //
                // `loop_help_request_created` est configurable parce qu'une
                // demande d'aide ne reclame rien a personne en particulier. Une
                // demande d'ADHESION, si : elle attend une decision du
                // proprietaire, et elle reste en attente tant qu'il ne l'a pas
                // prise. Se taire ici ne protege pas du bruit, ca laisse
                // quelqu'un a la porte.
                self::CHANNEL_IN_APP => ['default' => true, 'configurable' => false],

                // EMAIL actif par defaut — demande explicite de Cyril
                // (« une notification dans l'application ET par email »).
                // Configurable, parce que le fait reste visible dans le Centre :
                // couper l'email ne fait rien perdre.
                self::CHANNEL_EMAIL => ['default' => true, 'configurable' => true],

                // PAS de WEB_PUSH : Cyril ne l'a pas demande, et declarer un
                // canal touche DEUX enumerations plus les libelles de Push dans
                // les deux langues. A ouvrir si MASTER le veut — une ligne ici,
                // deux libelles, rien de plus.
            ],
        ],

        self::LOOP_JOIN_REQUEST_ACCEPTED => [
            'category' => 'loop',
            'intention' => self::INTENTION_CHANGEMENTS,
            'object_type' => self::OBJECT_LOOP_JOIN_REQUEST,
            'channels' => [
                self::CHANNEL_IN_APP => ['default' => true, 'configurable' => false],
                self::CHANNEL_EMAIL => ['default' => true, 'configurable' => true],

                // TASK-1744 — la reponse a une demande que la personne a FAITE
                // elle-meme et qu'elle attend : c'est le cas ou un Push sert le
                // mieux, parce qu'il arrive au moment ou la reponse arrive.
                //
                // Son jumeau « refuse » n'en recoit PAS, et c'est un arbitrage,
                // pas un oubli : CDC 6.2 le classe « plutot non au lancement »,
                // au titre de l'anti-bruit. Un refus n'ouvre aucune porte et
                // n'appelle aucun geste — le reveiller sur un ecran verrouille
                // ajouterait une peine sans rien permettre. Le fait reste
                // visible dans le Centre pour qui va voir.
                self::CHANNEL_WEB_PUSH => ['default' => true, 'configurable' => true],
            ],
        ],

        self::LOOP_JOIN_REQUEST_REJECTED => [
            'category' => 'loop',
            'intention' => self::INTENTION_CHANGEMENTS,
            'object_type' => self::OBJECT_LOOP_JOIN_REQUEST,
            'channels' => [
                self::CHANNEL_IN_APP => ['default' => true, 'configurable' => false],
            ],
        ],

        // TASK-1697 — l'auteur d'un article est prevenu qu'on l'a commente.
        self::BLOG_COMMENT_CREATED => [
            'category' => 'blog',
            'intention' => self::INTENTION_CHANGEMENTS,
            'object_type' => self::OBJECT_BLOG_COMMENT,
            'channels' => [
                // `configurable => true`, et c'est la difference avec une
                // invitation : un commentaire ne vous est pas ADRESSE et
                // n'appelle aucune reponse de vous. Un auteur prolifique doit
                // pouvoir se taire. Actif par defaut, parce qu'un auteur veut
                // savoir qu'on lui repond.
                self::CHANNEL_IN_APP => ['default' => true, 'configurable' => true],

                // TASK-1784 — Cyril revient sur deux decisions, les siennes :
                // « il faut notifier l'auteur du billet par email ET PWA si
                // quelqu'un a commente son billet. Et par defaut tout est
                // coche ».
                //
                // Ce que cela remplace, et qui est consigne ici pour qu'on
                // sache ce qu'on a change :
                //
                //  - l'EMAIL etait absent parce que « le catalogue ne declare
                //    que ce qui EXISTE, et Cyril a tranche *dans
                //    l'application* ». La note promettait « une ligne » ; c'est
                //    faux, et TASK-1784 l'a mesure : `rendreDepuisGabarit()`
                //    est un aiguillage ECRIT A LA MAIN, et un gabarit
                //    administrable exige le catalogue du seeder ET une
                //    migration. D'ou une TASK, pas une ligne ;
                //
                //  - le PUSH etait absent sur le CDC 6.2 : « un commentaire ne
                //    vous est pas adresse, n'appelle aucune reponse, et un
                //    auteur prolifique en recevrait des dizaines ».
                //
                // Cette derniere objection reste VRAIE, et c'est pourquoi les
                // deux canaux sont `configurable => true` : un auteur prolifique
                // doit pouvoir se taire canal par canal. « Tout coche par
                // defaut » decide du DEFAUT, pas de l'irrevocabilite.
                self::CHANNEL_EMAIL => ['default' => true, 'configurable' => true],
                self::CHANNEL_WEB_PUSH => ['default' => true, 'configurable' => true],
            ],
        ],

        // TASK-1702 — on vous a ajoute comme co-auteur d'un article.
        self::BLOG_CO_AUTHOR_ADDED => [
            'category' => 'blog',
            'intention' => self::INTENTION_SOLLICITATION_DIRECTE,
            'object_type' => self::OBJECT_BLOG_POST,
            'channels' => [
                // `configurable => false`, comme une invitation et a la
                // DIFFERENCE d'un commentaire : etre ajoute a l'ecriture d'un
                // article vous est ADRESSE personnellement et appelle un geste
                // de vous. Le commentaire, lui, se desactive — son entree
                // au-dessus l'explique.
                self::CHANNEL_IN_APP => ['default' => true, 'configurable' => false],

                // TASK-1744 — CDC 6.2 : « Oui si pertinent. Pas de details
                // prives ». Le meme raisonnement que pour `in_app` ci-dessus
                // vaut pour le Push : la co-ecriture vous est adressee.
                //
                // Et c'est precisement ce qui le separe de
                // `blog.comment.created`, qui reste SANS Push : un commentaire
                // ne vous est pas adresse, n'appelle aucune reponse, et un
                // auteur prolifique en recevrait des dizaines. CDC 6.2 :
                // « plutot non par defaut ».
                //
                // Pas de canal EMAIL ici, donc le Push est le SEUL canal
                // sortant de cette cle. Cela ne change rien a son defaut : le
                // defaut d'un canal ne se decide pas a la disponibilite des
                // autres.
                self::CHANNEL_WEB_PUSH => ['default' => true, 'configurable' => true],
            ],
        ],

        /*
         * TASK-1747 (CDC §8.1) — la demande d'aide publiee dans une Boucle.
         *
         * ## L'objet vise est la BOUCLE, et c'est un choix
         *
         * Pas un type « message de Boucle », qui aurait exige une branche de
         * resolution de plus, ses tests, et son acceptation par la garde de
         * coherence — pour aboutir au meme endroit. La branche `loop` existe,
         * elle est eprouvee, elle borne le tenant et verifie l'appartenance
         * active du destinataire. On s'en sert.
         *
         * Consequence assumee : deux demandes posees dans la meme Boucle
         * partagent leur `tag` de regroupement. Sans effet aujourd'hui, le
         * Push etant eteint ; a revoir le jour ou on l'ouvrira.
         */
        self::LOOP_HELP_REQUEST_CREATED => [
            'category' => 'loop',

            // Ni « on me sollicite » (ce n'est pas adresse personnellement),
            // ni « invitations et changements » (cela demande quelque chose).
            // Cette cle rend la troisieme section REELLE, alors qu'elle
            // n'etait que provisionnee depuis le §7A.
            'intention' => self::INTENTION_SOLLICITATION_PERTINENTE,

            'object_type' => self::OBJECT_LOOP,
            'channels' => [
                // TASK-1775 (arbitrage Cyril, 07/11, APRES avoir vu l'ecran
                // "Mes Boucles" en vrai) — redevenu OBLIGATOIRE, comme les six
                // cles personnelles. Le §7B avait rendu ce canal configurable
                // pour permettre de « se taire sans quitter la Boucle » ;
                // Cyril est revenu sur ce point une fois la sourdine par
                // Boucle (`LoopNotificationPreference`) livree : le VRAI
                // levier de calme est desormais l'interrupteur general que
                // CHAQUE MEMBRE possede sur CHACUNE de ses Boucles (plus
                // expose dans l'UI, mais toujours applique cote resolveur),
                // pas un reglage global sur ce seul canal.
                //
                // TASK-1778 — a ne pas lire comme un levier COLLECTIF :
                // l'interrupteur est individuel, personne ne coupe une Boucle
                // pour les autres. Voir le docblock de
                // `LoopNotificationPreference`.
                self::CHANNEL_IN_APP => ['default' => true, 'configurable' => false],

                // TASK-1775 (meme arbitrage) — ACTIF par defaut, et non plus
                // eteint (decision MASTER du 03/11, renversee le 07/11). La
                // sourdine par Boucle etant livree, Cyril a choisi l'opt-OUT
                // plutot que l'opt-IN pour ce canal : un membre recoit le
                // Push des sa premiere Boucle, et peut le couper Boucle par
                // Boucle s'il le souhaite.
                self::CHANNEL_WEB_PUSH => ['default' => true, 'configurable' => true],

                // TASK-1775 (meme arbitrage) — ACTIF par defaut, memes
                // raisons que WEB_PUSH ci-dessus. Decision CONSCIENTE d'un
                // opt-OUT plutot qu'un opt-IN : chaque membre d'une Boucle
                // recoit desormais un email par demande d'aide, sauf s'il le
                // desactive pour cette Boucle precise.
                self::CHANNEL_EMAIL => ['default' => true, 'configurable' => true],
            ],
        ],

        /*
         * TASK-1802 (demande Cyril) — un Evenement propose dans une Boucle.
         *
         * MEME forme que `LOOP_HELP_REQUEST_CREATED` ci-dessus — collectif,
         * destine a tous les membres actifs sauf l'auteur, calme par la
         * sourdine PAR BOUCLE plutot que par un reglage global sur l'in-app.
         * « Par defaut, les 3 options seront activees » (demande explicite) :
         * les trois canaux demarrent ACTIFS, email et Web Push restant
         * debrayables individuellement.
         */
        self::LOOP_EVENT_CREATED => [
            'category' => 'loop',
            'intention' => self::INTENTION_CHANGEMENTS,
            'object_type' => self::OBJECT_LOOP,
            'channels' => [
                self::CHANNEL_IN_APP => ['default' => true, 'configurable' => false],
                self::CHANNEL_WEB_PUSH => ['default' => true, 'configurable' => true],
                self::CHANNEL_EMAIL => ['default' => true, 'configurable' => true],
            ],
        ],

        /*
         * TASK-1751 — quelqu'un repond a votre annonce.
         *
         * Le destinataire doit DECIDER (accepter ou refuser) : c'est une
         * sollicitation directe, au meme titre qu'une invitation — et non une
         * information qu'on classe.
         *
         * A ne pas confondre avec `LOOP_HELP_REQUEST_CREATED`, qui est
         * COLLECTIF (une demande posee dans une Boucle, adressee a tout le
         * monde). TASK-1775 (07/11) a aligne ses defauts sur ceux-ci — Push et
         * email actifs — mais par un chemin different : la cle collective est
         * calmee Boucle par Boucle, celle-ci ne l'est pas, puisqu'une seule
         * personne est concernee, sur sa propre annonce.
         */
        self::TRANSACTION_PROPOSAL_CREATED => [
            'category' => 'transaction',
            'intention' => self::INTENTION_SOLLICITATION_DIRECTE,
            'object_type' => self::OBJECT_TRANSACTION,
            'channels' => [
                // OBLIGATOIRE, comme l'invitation : on ne se desabonne pas
                // d'apprendre qu'on a une decision a prendre. Et l'annonce
                // resterait sans reponse sans que son auteur sache pourquoi.
                self::CHANNEL_IN_APP => ['default' => true, 'configurable' => false],

                // Actif, et configurable. Actif parce qu'on ne peut pas
                // supposer que la personne reviendra d'elle-meme voir si
                // quelqu'un a repondu. Configurable parce que le fait reste
                // visible dans le Centre : couper l'email ne fait rien perdre.
                self::CHANNEL_EMAIL => ['default' => true, 'configurable' => true],

                // Aligne sur `LOOP_INVITATION`, pas sur l'evenement collectif.
                // Le defaut ne reveille personne a lui seul : il faut encore un
                // appareil abonne ET la permission systeme.
                self::CHANNEL_WEB_PUSH => ['default' => true, 'configurable' => true],
            ],
        ],

        // TASK-1786 — on a commente votre annonce d'entraide.
        self::ENTRAIDE_COMMENT_CREATED => [
            'category' => 'entraide',
            'intention' => self::INTENTION_CHANGEMENTS,
            'object_type' => self::OBJECT_ENTRAIDE_COMMENT,
            'channels' => [
                // Les TROIS canaux actifs par defaut et TOUS configurables.
                // C'est la demande du CDC, mot pour mot : « les trois canaux
                // sont actives par defaut et configurables ».
                //
                // `configurable => true` partout, et c'est coherent avec le
                // commentaire de blog plutot qu'avec une invitation : un
                // commentaire ne vous est pas ADRESSE et n'appelle aucune
                // reponse de vous. Un membre dont les annonces sont tres
                // commentees doit pouvoir se taire canal par canal. « Tout
                // coche par defaut » decide du DEFAUT, pas de
                // l'irrevocabilite.
                //
                // Et le defaut ne reveille personne a lui seul : le Push exige
                // encore un appareil abonne ET la permission du navigateur. Le
                // CDC le rappelle — « la configuration ne doit pas declencher
                // d'inscription Push automatique ».
                self::CHANNEL_IN_APP => ['default' => true, 'configurable' => true],
                self::CHANNEL_EMAIL => ['default' => true, 'configurable' => true],
                self::CHANNEL_WEB_PUSH => ['default' => true, 'configurable' => true],
            ],
        ],

        // TASK-1810 — on a commente un item du Flux (annonce manuelle ou
        // evenement automatique).
        self::FEED_POST_COMMENT_CREATED => [
            'category' => 'feed',

            // Ni « on me sollicite » (un commentaire n'appelle aucune
            // reponse de ma part), ni une sollicitation DIRECTE : meme
            // classement que `ENTRAIDE_COMMENT_CREATED` et
            // `BLOG_COMMENT_CREATED` juste au-dessus, pour la meme raison.
            'intention' => self::INTENTION_CHANGEMENTS,

            'object_type' => self::OBJECT_FEED_POST_COMMENT,
            'channels' => [
                // Les TROIS canaux actifs par defaut et TOUS configurables —
                // demande explicite de Cyril (« faut envoyer une
                // notification par email + app + pwa »), et le meme
                // raisonnement que les commentaires de blog/entraide : un
                // commentaire ne vous est pas ADRESSE et n'appelle aucune
                // reponse de vous, donc aucun canal n'est OBLIGATOIRE.
                self::CHANNEL_IN_APP => ['default' => true, 'configurable' => true],
                self::CHANNEL_EMAIL => ['default' => true, 'configurable' => true],
                self::CHANNEL_WEB_PUSH => ['default' => true, 'configurable' => true],
            ],
        ],

        // TASK-1810 — on a reagi a un item du Flux.
        self::FEED_POST_REACTION_CREATED => [
            'category' => 'feed',
            'intention' => self::INTENTION_CHANGEMENTS,
            'object_type' => self::OBJECT_FEED_POST,
            'channels' => [
                // PREMIERE cle du catalogue sans aucun canal asynchrone —
                // voir le docblock de la constante pour ce que cela entraine
                // (aucune branche requise dans `NotificationEmailDeliverer`).
                // « Juste une reaction » reste configurable : un membre dont
                // les items recoivent beaucoup de reactions doit pouvoir se
                // taire, exactement comme pour un commentaire.
                self::CHANNEL_IN_APP => ['default' => true, 'configurable' => true],
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

    /**
     * L'intention de cette cle — ce que l'evenement DEMANDE a la personne.
     *
     * Rend `null` pour une cle inconnue, comme ses voisines. Une cle CONNUE
     * qui n'en declare pas est en revanche un defaut, pas un cas limite : la
     * garde de `TASK1746PreferenceIntentionsTest` echoue alors, pour qu'une
     * cle ajoutee demain ne puisse pas disparaitre de l'ecran en silence.
     */
    public static function intentionFor(string $notificationKey): ?string
    {
        return self::definition($notificationKey)['intention'] ?? null;
    }

    /**
     * Les cles de chaque intention, dans l'ordre d'affichage declare.
     *
     * Les intentions SANS cle sont rendues elles aussi, avec un tableau vide :
     * c'est a l'appelant de decider quoi en faire, et l'ecran choisit de ne
     * pas les afficher. Les omettre ici cacherait au contraire qu'une
     * intention est declaree mais vide — ce qui est une information.
     *
     * @return array<string, list<string>>
     */
    public static function keysByIntention(): array
    {
        $parIntention = array_fill_keys(self::INTENTIONS, []);

        foreach (self::ENTRIES as $cle => $definition) {
            $intention = $definition['intention'] ?? null;

            if ($intention === null || ! array_key_exists($intention, $parIntention)) {
                continue;
            }

            $parIntention[$intention][] = $cle;
        }

        return $parIntention;
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

    /**
     * TASK-1778 — l'identifiant de Boucle qui gouverne une preference, ou `null`.
     *
     * ## Pourquoi cette regle vit ICI et nulle part ailleurs
     *
     * Elle etait ecrite TROIS FOIS a l'identique en T1775 :
     * `NotificationPreferenceResolver::allows()` en ligne,
     * `NotificationEmailDeliverer::loopIdPourPreference()` et
     * `NotificationWebPushDeliverer::livrer()`. T1778 lui ajoutait un
     * quatrieme lecteur — le planificateur — et une regle de securite recopiee
     * quatre fois est une regle qui finira par diverger en trois endroits sur
     * quatre, la copie oubliee devenant une fuite silencieuse.
     *
     * ## La regle porte sur la CLE, jamais sur `object_type`
     *
     * `LOOP_MEMBER_ADDED` partage le meme `object_type` (`OBJECT_LOOP`) que
     * `LOOP_HELP_REQUEST_CREATED` et son `object_id` designe aussi une Boucle.
     * Mais « on vous a ajoute » est un fait PERSONNEL : la sourdine de Boucle
     * ne doit jamais le faire taire. Seule la cle collective explicite se
     * laisse gouverner par un reglage par Boucle.
     *
     * Rendre `null` n'est donc pas un repli : c'est la reponse juste pour
     * toutes les autres cles.
     */
    public static function loopScopeFor(string $notificationKey, ?string $objectId): ?string
    {
        // TASK-1802 — LOOP_EVENT_CREATED rejoint LOOP_HELP_REQUEST_CREATED :
        // meme fait collectif, meme `object_type` (OBJECT_LOOP), donc le meme
        // `object_id` EST deja l'identifiant de la Boucle.
        if (! in_array($notificationKey, [self::LOOP_HELP_REQUEST_CREATED, self::LOOP_EVENT_CREATED], true)) {
            return null;
        }

        return blank($objectId) ? null : (string) $objectId;
    }
}
