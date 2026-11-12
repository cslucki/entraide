<?php

return [
    // Le Centre
    'title' => 'Mes notifications',
    'subtitle' => 'Ce que BouclePro a voulu vous signaler.',

    // Les filtres
    'filter_all' => 'Toutes',
    'filter_unread' => 'Non lues',

    // Les actions
    'mark_read' => 'Marquer comme lu',
    'mark_all_read' => 'Tout marquer comme lu',
    'mark_unread' => 'Marquer comme non lue',

    // Les etats vides — DEUX, et c'est voulu. Une page vide et un filtre vide
    // ne disent pas la meme chose : la premiere dit « rien ne vous attend »,
    // la seconde « rien ICI, mais ailleurs oui ». Les confondre laisserait
    // croire a une boite vide alors qu'elle ne l'est pas.
    'empty_title' => 'Aucune notification',
    'empty_body' => 'Vous serez prévenu ici quand quelque chose vous concernera.',
    'filter_empty_title' => 'Tout est lu',
    'filter_empty_body' => 'Aucune notification non lue pour le moment.',

    // L'etat de lecture
    'target_unreachable' => "Cet élément n'est plus accessible.",
    'open' => 'Ouvrir',
    'unread_badge' => 'Non lue',

    // Les libelles par cle du catalogue. Les points de la cle deviennent des
    // tirets bas : `loop.invitation` -> `keys.loop_invitation`.
    'preferences_title' => 'Réglages des notifications',
    'preferences_subtitle' => 'Choisissez ce que BouclePro vous envoie.',
    'preferences_save' => 'Enregistrer',
    'preferences_saved' => 'Vos réglages ont été enregistrés.',
    'preferences_mandatory' => 'Toujours active',

    /*
     * TASK-1746 (CDC §7A) — les sections de l'ecran, nommees par ce que
     * l'evenement DEMANDE a la personne, jamais par l'objet technique dont il
     * parle. « Boucles » et « Blog » auraient classe par domaine ; ici on
     * repond a « pourquoi suis-je prevenu, et que dois-je en faire ».
     *
     * La troisieme est provisionnee pour le §9.2 et reste vide : une section
     * sans cle eligible n'est pas affichee.
     */
    'intentions' => [
        'sollicitation_directe' => [
            'title' => 'Quand on me sollicite',
            'hint' => 'Ce qui vous est adressé personnellement et attend une réponse de votre part.',
        ],
        'changements' => [
            'title' => 'Invitations et changements',
            'hint' => 'Ce qui vous concerne sans rien exiger : on vous tient au courant.',
        ],
        'sollicitation_pertinente' => [
            'title' => 'Sollicitations pertinentes',
            // TASK-1747 — elargi : la section n'est plus seulement celle du
            // matching a venir, elle porte des maintenant les demandes
            // publiees dans les Boucles dont on est membre.
            'hint' => 'Les demandes auxquelles vous pourriez répondre : celles de vos Boucles, et celles qui correspondent à ce que vous savez faire.',
        ],
    ],
    'preferences_mandatory_hint' => 'Cette notification vous est adressée personnellement et appelle une réponse : elle ne peut pas être désactivée.',

    // TASK-1775 (CDC B1) — les reglages COLLECTIFS, Boucle par Boucle. Place
    // AVANT le reste de l'ecran : c'est la premiere decision, « de quelle
    // Boucle je veux entendre parler ».
    'loops' => [
        'title' => 'Mes Boucles',
        'hint' => "Les demandes d'aide publiées dans vos Boucles se règlent Boucle par Boucle.",
        'master_label' => 'Recevoir les notifications',
        'empty_message' => "Vous n'avez pas encore rejoint de Boucle. Rejoignez-en une pour participer aux entraides et recevoir les demandes d'aide.",
        'join_cta' => 'Rejoindre une boucle',
    ],
    'preferences_link' => 'Réglages',
    'preferences_back' => 'Mes notifications',
    'channel_in_app' => "Dans l'application",
    'channel_email' => 'Par email',
    // TASK-1740 — troisieme canal. Le libelle nomme l'APPAREIL, pas la
    // technique : « web push » ne veut rien dire pour la personne qui regle.
    // TASK-1775 (demande Cyril, 07/11) — remplace "Sur mes appareils" :
    // nomme l'APPLICATION installee, pas l'appareil physique.
    'channel_web_push' => 'Sur l\'application téléchargée',

    // TASK-1740 — les textes qui partent sur un ecran VERROUILLE.
    //
    // CDC 6.4 : aucun nom de personne, aucun extrait, aucun nom de Boucle
    // CONFIDENTIELLE. Ils doivent rester vrais devant n'importe qui — le
    // detail se lit APRES le clic, sur une page que le serveur re-autorise.
    //
    // TASK-1744 nuance comme le CDC lui-meme nuance : le nom d'une Boucle
    // PUBLIQUE a le droit de voyager (6.2 ecrit « pas de nom de Boucle
    // PRIVEE »), et le livreur est le seul a en decider — voir
    // `nomDeBouclePublique()`.
    'push_title_fallback' => 'BouclePro',
    'push_body_generic' => 'Touchez pour voir.',
    /*
     * TASK-1744 — un CORPS par evenement.
     *
     * « Touchez pour voir » ne disait rien que le titre ne disait
     * deja. Il reste le dernier recours, pour une cle qui n'aurait
     * pas sa phrase.
     *
     * Ces phrases ne servent QUE lorsque la Boucle n'est pas
     * publique : quand elle l'est, le livreur met son NOM a la
     * place, qui est ce que la personne cherche.
     */
    'push_body' => [
        'loop_mention_created' => 'Ouvrez BouclePro pour lire le message.',
        'loop_invitation' => 'Ouvrez BouclePro pour répondre.',
        'loop_member_added' => 'Vous pouvez maintenant y participer.',
        'loop_join_request_accepted' => 'Vous pouvez maintenant y participer.',
        'blog_comment_created' => 'Ouvrez l’article pour lire le commentaire.',
        // TASK-1786 — GENERIQUE a dessein. Le CDC T1786 §9 proposait « Marie a
        // commente votre proposition d'entraide » ; la doctrine du depot, posee
        // par le CDC notifications §6.4/6.5 et redite dans
        // `NotificationCenterController`, veut l'inverse : un Push s'affiche sur
        // un ECRAN VERROUILLE, et nommer la personne y exposerait l'entraide de
        // quelqu'un a qui passe devant le telephone. Le fait se raconte en
        // entier dans `keys_named`, qui n'est lisible qu'authentifie.
        'entraide_comment_created' => 'Ouvrez BouclePro pour lire la suggestion.',
        'blog_co_author_added' => 'Ouvrez l’article pour commencer.',

        // TASK-1747 — ne nomme NI la Boucle NI la personne : une demande
        // d'aide peut etre posee dans une Boucle privee, et le corps d'un
        // Push s'affiche sur un ecran verrouille.
        'loop_help_request_created' => 'Ouvrez BouclePro pour voir si vous pouvez aider.',

        // TASK-1751 — ni la personne, ni le titre de l'annonce.
        'transaction_proposal_created' => 'Ouvrez BouclePro pour voir la proposition.',

        // TASK-1802 — ni la Boucle ni l'Evenement : un Push s'affiche sur un
        // ecran VERROUILLE, et la Boucle peut etre privee.
        'loop_event_created' => 'Ouvrez BouclePro pour voir les details.',

        // TASK-1810 — ni la personne, ni le contexte de l'item : un Push
        // s'affiche sur un ecran VERROUILLE, meme doctrine que ses voisines.
        'feed_post_comment_created' => 'Ouvrez le Flux pour lire le commentaire.',
    ],

    'push_title' => [
        // TASK-1772 — ni le nom de la personne, ni celui de la Boucle :
        // ce texte s'affiche sur un ecran VERROUILLE. Le corps sera
        // complete du nom de la Boucle si elle est PUBLIQUE, par
        // `nomDeBouclePublique()`, et seulement dans ce cas.
        'loop_mention_created' => 'On vous a mentionné',
        'loop_invitation' => 'Une invitation vous attend',

        // TASK-1751 — ni le nom de la personne, ni le titre de l'annonce :
        // ce texte s'affiche sur un écran VERROUILLÉ (CDC §6.4/6.5).
        'transaction_proposal_created' => 'On répond à votre demande d’aide',
        'loop_member_added' => 'Vous avez rejoint une Boucle',
        'loop_join_request_accepted' => 'Votre demande a été acceptée',

        // TASK-1744 — la quatrième clé ouverte au Push. Le titre ne nomme pas
        // l'article : un brouillon co-écrit n'a aucune raison de s'afficher
        // devant n'importe qui.
        'blog_comment_created' => 'Un nouveau commentaire sur votre article',
        'blog_co_author_added' => 'On vous associe à un article',

        // TASK-1786 — le titre exact du CDC §9, qui ne nomme ni la personne ni
        // l'annonce : il est donc compatible avec l'ecran verrouille.
        'entraide_comment_created' => 'Nouvelle suggestion sur votre entraide',

        // TASK-1747 — le canal est ETEINT par defaut, mais le libelle doit
        // exister : la garde de coherence l'exige des qu'un canal est
        // declare, et l'oublier ferait rougir le jour de l'ouverture.
        'loop_help_request_created' => 'Une demande d’aide dans votre Boucle',

        // TASK-1802 — meme doctrine que sa voisine ci-dessus : ni la Boucle,
        // ni l'Evenement, un Push s'affichant sur un ecran verrouille.
        'loop_event_created' => 'Un nouvel évènement dans votre Boucle',

        // TASK-1810 — meme doctrine : ni la personne, ni le contexte de
        // l'item, sur un ecran verrouille.
        'feed_post_comment_created' => 'Nouveau commentaire sur le Flux',
    ],

    'keys' => [
        'loop_mention_created' => 'On vous a mentionné dans une Boucle',
        // TASK-1761 — la DEMANDE, cote proprietaire. Ses deux voisines
        // portent la DECISION et vont au demandeur.
        'loop_join_request_created' => 'Quelqu’un demande à rejoindre votre Boucle',
        // TASK-1702
        'blog_co_author_added' => 'On vous a ajouté comme co-auteur d’un article',

        // TASK-1697
        'blog_comment_created' => 'Un nouveau commentaire sur votre article',

        // TASK-1786 — le libelle que le CDC §6 demande pour « Reglages des
        // notifications », et qui sert aussi de repli dans le Centre quand
        // l'annonce n'a pas pu etre nommee.
        'entraide_comment_created' => 'Commentaires sur mes propositions et demandes d’aide',
        'loop_invitation' => 'Invitation à rejoindre une Boucle',
        'loop_member_added' => 'Ajout à une Boucle',

        // TASK-1747 (CDC §8.1)
        'loop_help_request_created' => 'Une demande d’aide a été publiée',

        // TASK-1751 — le libellé ne dit pas « service » ou « demande » :
        // une seule clé sert les deux sens de l'échange, et le fait vu du
        // destinataire est le même — on répond à SON annonce.
        'transaction_proposal_created' => 'Quelqu’un répond à votre demande ou proposition d’aide',

        // TASK-1381 — deux libellés, parce que ce sont deux faits. Le membre
        // doit savoir ce qui lui est arrivé sans avoir à ouvrir quoi que ce soit.
        'loop_join_request_accepted' => 'Votre demande d’adhésion a été acceptée',
        'loop_join_request_rejected' => 'Votre demande d’adhésion n’a pas été retenue',

        // TASK-1802 (demande Cyril) — un nouvel évènement proposé dans une Boucle.
        'loop_event_created' => 'Un nouvel évènement a été proposé',

        // TASK-1810 (demande Cyril, 12/11) — on a commente ou réagi à un item du Flux.
        'feed_post_comment_created' => 'Commentaires sur mes items du Flux',
        'feed_post_reaction_created' => 'Réactions sur mes items du Flux',
    ],

    /*
     * TASK-1725 (demande Cyril, 29/10) — les MEMES faits, en nommant la Boucle.
     *
     * « Ajout a une Boucle » ne disait pas LAQUELLE : un membre de plusieurs
     * Boucles devait ouvrir pour savoir de quoi on lui parlait, ce que le
     * libelle existe precisement pour lui epargner.
     *
     * Un groupe SEPARE, et non un `:loop` ajoute au groupe `keys` : le nom
     * n'est pas toujours connu au rendu — Boucle supprimee, ou d'un autre
     * tenant — et une phrase a trou qui recevrait une chaine vide donnerait
     * « Ajout a la Boucle «  » ». Les deux formes coexistent donc, et la vue
     * choisit selon ce qu'elle a. `keys` reste la forme sans objet.
     *
     * Les cles ici sont un SOUS-ENSEMBLE de `keys` : seules les notifications
     * dont l'OBJET peut etre nomme en ont une.
     *
     * TASK-1757 — ce n'etait « une Boucle » que jusqu'ici. Une entraide se
     * nomme aussi (prenom, titre de l'annonce, temps), et le parametre n'est
     * donc plus toujours `:loop` : la vue passe la carte de parametres que le
     * controleur a pu resoudre, quelle qu'elle soit.
     */
    'keys_named' => [
        // TASK-1772 — nommee UNIQUEMENT ici : le Centre est derriere
        // l'authentification. `push_title` et le sujet d'email restent
        // generiques (ecran verrouille, apercu de boite mail).
        'loop_mention_created' => '« :prenom » vous a mentionné dans la Boucle « :loop »',
        // TASK-1797 — cle DISTINCTE, et non une reecriture de celle du
        // dessus : une mention collective ne vous a pas nommement appele,
        // le dire ainsi serait une interpellation nominative abusive. Meme
        // `notification_key` (`loop.mention.created`), meme destinataire,
        // meme evenement — seul le TEXTE change, lu par
        // `sujetsDesMentions()` quand le message porte `mention_all`.
        'loop_mention_all_created' => '« :prenom » a mentionné tous les membres de la Boucle « :loop »',
        // TASK-1757 — nommee UNIQUEMENT ici : la liste en-appli est derriere
        // l'authentification. `push_title` et le sujet d'email restent
        // generiques (ecran verrouille, apercu de boite mail).
        'transaction_proposal_created' => ':prenom répond à « :titre » — :temps',
        // TASK-1786 — nommee UNIQUEMENT ici, meme doctrine que ses voisines : le
        // Centre est derriere l'authentification. C'est le texte du CDC §7, et
        // `:type` porte « proposition d'entraide » ou « demande d'aide », resolu
        // par le controleur — ce qui permet a UNE cle de servir les deux.
        'entraide_comment_created' => ':prenom a commenté votre :type « :titre »',
        'loop_invitation' => 'Invitation à rejoindre la Boucle « :loop »',
        'loop_member_added' => 'Ajout à la Boucle « :loop »',
        'loop_join_request_accepted' => 'Votre demande d’adhésion à « :loop » a été acceptée',
        'loop_join_request_rejected' => 'Votre demande d’adhésion à « :loop » n’a pas été retenue',

        // TASK-1775 (CDC C4) — nommee UNIQUEMENT ici, meme doctrine que ses
        // voisines : le Centre est derriere l'authentification, `push_title`
        // reste generique (ecran verrouille).
        'loop_help_request_created' => 'Une demande d’aide a été publiée dans la Boucle « :loop »',

        // TASK-1802 — nommee UNIQUEMENT ici, meme raisonnement que sa voisine.
        'loop_event_created' => 'Un nouvel évènement a été proposé dans la Boucle « :loop »',
    ],

    // TASK-1725 — « Ouvrir » ne disait pas quoi. Le libelle depend donc de
    // l'objet : une notification de Boucle mene a une Boucle, et le dire evite
    // au membre de deviner ou le bouton l'emmene.
    'open_loop' => 'Ouvrir la Boucle',

    // Repli quand une cle du catalogue n'a pas encore de libelle. Il ne doit
    // jamais afficher la cle technique : elle ne veut rien dire pour un membre.
    // TASK-1772 — un message peut n'etre QU'une photo : `loop_messages.body`
    // est alors vide, et le bloc de citation du courriel aurait l'air casse.
    'mention_email_excerpt_empty' => '(message sans texte)',

    'key_fallback' => 'Notification',

    /*
    |--------------------------------------------------------------------------
    | Emails de notification (TASK-1378)
    |--------------------------------------------------------------------------
    |
    | Le sous-tableau est nomme d'apres la cle du catalogue, POINTS REMPLACES :
    | `loop.invitation` devient `loop_invitation`. Un point y serait lu comme
    | une hierarchie de groupes par le resolveur de traductions, et la cle
    | deviendrait introuvable pour une raison sans rapport avec son contenu.
    |
    | `:url` est rendu DEUX FOIS par le livreur : avec la vraie adresse pour
    | l'envoi, avec un marqueur expurge pour l'archivage. Ne pas construire de
    | lien en dur ici : ce sont ces deux rendus qui protegent le jeton.
    |
    */
    'blog_comment_email_excerpt_empty' => '(commentaire sans texte)',
    // TASK-1786 — meme repli pour un commentaire d'entraide sans texte.
    'entraide_comment_email_excerpt_empty' => '(commentaire sans texte)',
    // TASK-1810 — meme repli pour un commentaire de Flux sans texte.
    'feed_post_comment_email_excerpt_empty' => '(commentaire sans texte)',

    'email' => [
        // TASK-1772 — REPLI. Le rendu passe normalement par le gabarit
        // administrable `loop_mention_created` ; ceci ne sert que si le
        // gabarit est desactive ou absent.
        // TASK-1784 — REPLI. Le rendu passe normalement par le gabarit
        // administrable `blog_comment_created` ; ceci ne sert que si le
        // gabarit est desactive ou absent.
        //
        // `:url`, `:reglages`, `:titre` et `:extrait` restent des JETONS.
        // Une adresse en dur ici echapperait a l'expurgation du livreur et
        // finirait telle quelle dans `email_logs` — garde
        // `TASK1379…::test_every_email_body_uses_the_url_placeholder`.
        // TASK-1786 — REPLI de langue. Le gabarit qui fait foi est
        // l'administrable `entraide_comment_created` (seeder + migration) ; ceci
        // ne sert que s'il manque. Sujet GENERIQUE : un apercu de boite mail se
        // lit sans deverrouiller, meme doctrine que `push_title`.
        // TASK-1808 §6 — REPLI. Le gabarit qui fait foi est l'administrable
        // `entraide_evaluation_invite` (seeder + migration) ; ceci ne sert
        // que s'il manque. `:aidant` et `:url` restent des JETONS, meme
        // regle que les reprs ci-dessus.
        'entraide_evaluation_invite' => [
            'subject' => 'Un petit retour sur l’aide reçue ?',
            'body' => '<p>Bonjour,</p><p>Votre entraide avec :aidant est terminée.</p><p>Vous pouvez lui laisser une évaluation.</p><p><a href=":url">Laisser une évaluation</a></p><p>Si le lien ne fonctionne pas, copiez cette adresse dans votre navigateur :<br>:url</p><p>Merci de faire vivre l’entraide sur BouclePro.</p>',
        ],

        // TASK-1809 §4 — REPLI. Le gabarit qui fait foi est l'administrable
        // `entraide_review_received` (seeder + migration) ; ceci ne sert que
        // s'il manque. `:aide`, `:etoiles`, `:note`, `:commentaire` restent
        // des JETONS, meme regle que les reprs ci-dessus.
        'entraide_review_received' => [
            'subject' => ':aide vous a laissé une évaluation !',
            'body' => '<p>Bonjour,</p><p>:aide vous a laissé une évaluation pour votre entraide.</p><p style="font-size:20px;letter-spacing:2px">:etoiles <span style="font-size:14px;color:#777">(:note)</span></p><p>:commentaire</p><p>Merci de faire vivre l’entraide sur BouclePro.</p>',
        ],

        'entraide_comment_created' => [
            'subject' => 'Un membre a commenté votre entraide',
            'body' => '<p>Bonjour,</p><p>Un membre a laissé un commentaire sur votre entraide.</p><p><a href=":url">Voir le commentaire</a></p><p>Si le lien ne fonctionne pas, copiez cette adresse dans votre navigateur :<br>:url</p><p>Un commentaire n’engage rien : ni entraide engagée, ni acceptation d’aide, ni temps promis.</p><p>À bientôt,<br>L’équipe BouclePro</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">Pour ne plus recevoir cet email, allez dans <a href=":reglages">Réglages des notifications</a>.</p>',
        ],

        'blog_comment_created' => [
            'subject' => 'On a commenté votre article',
            'body' => '<p>Bonjour,</p><p>Une personne a laissé un commentaire sur votre article <strong>:titre</strong>.</p><blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #ccc;white-space:pre-line">:extrait</blockquote><p><a href=":url">Lire le commentaire</a></p><p>Si le lien ne fonctionne pas, copiez cette adresse dans votre navigateur :<br>:url</p><p>Rien ne presse : le commentaire vous attend, vous y répondrez quand vous voudrez.</p><p>À bientôt,<br>L’équipe BouclePro</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">Pour ne plus recevoir cet email, allez dans <a href=":reglages">Réglages des notifications</a>.</p>',
        ],

        // TASK-1810 — repli, meme doctrine : `:prenom` (le prénom du
        // commentateur), `:contexte` (ce qui est affiché dans le Flux) et
        // `:extrait` (le texte du commentaire) restent des JETONS, jamais
        // une adresse en dur.
        'feed_post_comment_created' => [
            'subject' => 'Commentaire sur le flux',
            'body' => '<p>Bonjour,</p><p><strong>:prenom</strong> vient de commenter le flux à propos d’un sujet qui vous concerne : <strong>:contexte</strong></p><blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #ccc;white-space:pre-line">:extrait</blockquote><p><a href=":url">Voir dans le Flux</a></p><p>Si le lien ne fonctionne pas, copiez cette adresse dans votre navigateur :<br>:url</p><p>Rien ne presse : vous n’avez pas besoin de vous connecter pour en prendre connaissance.</p><p>À bientôt,<br>L’équipe BouclePro</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">Pour ne plus recevoir cet email, allez dans <a href=":reglages">Réglages des notifications</a>.</p>',
        ],
        'loop_mention_created' => [
            'subject' => 'On vous a mentionné dans une Boucle',
            // TASK-1772 (recette 06/11) — `:extrait` porte le message, et
            // `:reglages` le lien de desabonnement. Les DEUX restent des
            // jetons : une adresse en dur ici echapperait a l'expurgation du
            // livreur et finirait telle quelle dans `email_logs` (garde
            // `TASK1379…::test_every_email_body_uses_the_url_placeholder`).
            'body' => '<p>Bonjour,</p><p>Une personne vous a mentionné dans un message d’une Boucle.</p><blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #ccc;white-space:pre-line">:extrait</blockquote><p><a href=":url">Lire le message</a></p><p>Si le lien ne fonctionne pas, copiez cette adresse dans votre navigateur :<br>:url</p><p>Rien ne presse : le message vous attend, vous le lirez quand vous voudrez.</p><p>À bientôt,<br>L’équipe BouclePro</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">Pour ne plus recevoir cet email, allez dans <a href=":reglages">Réglages des notifications</a>.</p>',
        ],
        // TASK-1797 — REPLI de la mention COLLECTIVE. Sert uniquement si le
        // gabarit administrable `loop_mention_all_created` est absent ou
        // desactive ; le rendu normal passe par lui (seeder + migration).
        // Le texte ne dit jamais « vous a mentionné » : personne ne vous a
        // nommement appele.
        'loop_mention_all_created' => [
            'subject' => 'On a mentionné tous les membres d’une Boucle',
            'body' => '<p>Bonjour,</p><p>Une personne a mentionné tous les membres d’une Boucle dont vous faites partie.</p><blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #ccc;white-space:pre-line">:extrait</blockquote><p><a href=":url">Lire le message</a></p><p>Si le lien ne fonctionne pas, copiez cette adresse dans votre navigateur :<br>:url</p><p>Rien ne presse : le message vous attend, vous le lirez quand vous voudrez.</p><p>À bientôt,<br>L’équipe BouclePro</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">Pour ne plus recevoir cet email, allez dans <a href=":reglages">Réglages des notifications</a>.</p>',
        ],
        'loop_join_request_created' => [
            'subject' => 'Quelqu’un demande à rejoindre votre Boucle',
            'body' => '<p>Bonjour,</p>'
                .'<p>Une personne demande à rejoindre une Boucle dont vous êtes responsable.</p>'
                .'<p><a href=":url">Voir la demande</a></p>'
                .'<p>Si le lien ne fonctionne pas, copiez cette adresse dans votre navigateur :<br>:url</p>'
                .'<p>À bientôt,<br>L’équipe BouclePro</p>',
        ],
        // TASK-1751 — REPLI. Le rendu passe normalement par le gabarit
        // administrable `transaction_proposal_created` ; ce texte sert si
        // le gabarit est absent ou désactivé. La suite de cohérence
        // l'exige dès que le canal `email` est déclaré.
        'transaction_proposal_created' => [
            'subject' => 'Quelqu’un répond à votre demande ou proposition d’aide',
            'body' => '<p>Bonjour,</p>'
                .'<p>Quelqu’un vient de répondre à une demande ou une proposition d’aide que vous avez publiée sur BouclePro.</p>'
                .'<p><a href=":url">Voir l’entraide</a></p>'
                .'<p>Si le lien ne fonctionne pas, copiez cette adresse dans votre navigateur :<br>:url</p>'
                .'<p>À bientôt,<br>L’équipe BouclePro</p>',
        ],
        'loop_invitation' => [
            'subject' => 'Vous êtes invité à rejoindre une boucle',
            'body' => '<p>Bonjour,</p>'
                .'<p>Vous êtes invité à rejoindre une boucle sur BouclePro.</p>'
                .'<p><a href=":url">Voir l\'invitation</a></p>'
                .'<p>Si le lien ne fonctionne pas, copiez cette adresse dans votre navigateur :<br>:url</p>'
                .'<p>À bientôt,<br>L\'équipe BouclePro</p>',
        ],
        'loop_member_added' => [
            'subject' => 'Vous avez été ajouté à une Boucle',
            'body' => '<p>Bonjour,</p>'
                .'<p>Vous avez été ajouté à une Boucle sur BouclePro. Vous en êtes désormais membre.</p>'
                .'<p><a href=":url">Ouvrir la Boucle</a></p>'
                .'<p>Si le lien ne fonctionne pas, copiez cette adresse dans votre navigateur :<br>:url</p>'
                .'<p>À bientôt,<br>L\'équipe BouclePro</p>',
        ],
        // TASK-1725 — dernier recours pour la demande d'adhesion ACCEPTEE.
        //
        // Trois couches, de la plus specifique a la plus generale :
        // `SystemEmailTemplate` (autorite administrable, par Organization et
        // locale), puis la vue `emails.loop-join-request-accepted` quand le
        // gabarit manque ou echoue, puis CES clefs quand le livreur ne sait
        // meme plus de quel objet il parle. Volontairement neutre : elle ne
        // nomme ni la Boucle ni le decideur, parce qu'a ce niveau on ne les a
        // plus. Le contrat de coherence TASK-1379 l'exige pour toute cle
        // portant le canal EMAIL.
        'loop_join_request_accepted' => [
            'subject' => 'Votre demande pour rejoindre une Boucle a été acceptée',
            'body' => '<p>Bonjour,</p>'
                .'<p>Votre demande pour rejoindre une Boucle sur BouclePro a été acceptée. Vous en êtes désormais membre.</p>'
                .'<p><a href=":url">Ouvrir la Boucle</a></p>'
                .'<p>Si le lien ne fonctionne pas, copiez cette adresse dans votre navigateur :<br>:url</p>'
                .'<p>À bientôt,<br>L\'équipe BouclePro</p>',
        ],

        // TASK-1775 (CDC C3) — ultime repli pour « une demande d'aide a ete
        // publiee ». Meme doctrine que ses voisines : volontairement neutre,
        // car a ce niveau ni la Boucle ni l'auteur ne sont plus connus. Exige
        // par la suite de coherence TASK-1379 des que le canal EMAIL est
        // declare.
        'loop_help_request_created' => [
            'subject' => 'Une demande d’aide a été publiée dans une Boucle',
            'body' => '<p>Bonjour,</p>'
                .'<p>Une demande d’aide a été publiée dans une Boucle dont vous êtes membre.</p>'
                .'<p><a href=":url">Ouvrir la Boucle</a></p>'
                .'<p>Si le lien ne fonctionne pas, copiez cette adresse dans votre navigateur :<br>:url</p>'
                .'<p>À bientôt,<br>L\'équipe BouclePro</p>',
        ],

        // TASK-1802 — REPLI. Le rendu passe normalement par le gabarit
        // administrable `loop_event_created` (seeder + migration) ; ceci ne
        // sert que si le gabarit est absent ou desactive. Volontairement
        // neutre : a ce niveau, ni la Boucle ni l'Evenement ne sont plus
        // connus. `:url`, `:reglages`, `:titre`, `:date`, `:boucle` et
        // `:infos` restent des JETONS — une adresse en dur echapperait a
        // l'expurgation du livreur (garde TASK1379…::
        // test_every_email_body_uses_the_url_placeholder).
        'loop_event_created' => [
            'subject' => ':titre — :date',
            'body' => '<p>Bonjour,</p>'
                .'<p>Un nouvel évènement a été proposé dans la Boucle <strong>:boucle</strong> : <strong>:titre</strong>, le :date.</p>'
                .'<p>:infos</p>'
                .'<p>:description</p>'
                .'<p><a href=":url">Ouvrir la Boucle</a></p>'
                .'<p>Si le lien ne fonctionne pas, copiez cette adresse dans votre navigateur :<br>:url</p>'
                .'<p>À bientôt,<br>L\'équipe BouclePro</p>'
                .'<hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">Pour ne plus recevoir cet email, allez dans <a href=":reglages">Réglages des notifications</a>.</p>',
        ],
    ],

    // TASK-1802 — les trois formes de rencontre, composees par
    // `LoopEventMailer` SERVEUR COTE, jamais dans le gabarit (les courriels
    // administrables n'ont pas de conditionnelle). Le lien de connexion est
    // du texte ici, pas un `<a>` : c'est la ligne d'action dediee
    // (« Ouvrir la Boucle ») qui porte le lien cliquable.
    'loop_event_email_description_empty' => '(pas de description)',
    'loop_event_email_practical_online' => 'En ligne — lien de connexion : :url',
    'loop_event_email_practical_hybrid' => 'Hybride — sur place : :location, ou en ligne : :url',
    'loop_event_email_practical_in_person' => 'Sur place : :location',
];
