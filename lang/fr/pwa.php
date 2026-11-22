<?php

/*
|--------------------------------------------------------------------------
| TASK-1737 — l'invitation a installer BouclePro
|--------------------------------------------------------------------------
|
| ## Les trois benefices sont VRAIS AUJOURD'HUI
|
| Le CDC (section 3.2) interdit de promettre la reception de notifications si
| elle n'est pas livree. La meme regle vaut pour le PARTAGE, qui attend la TASK
| suivante : les deux seraient des promesses creuses.
|
| Les trois lignes retenues sont donc verifiables des maintenant :
|
|   1. une icone sur l'ecran d'accueil — c'est ce que l'installation fait ;
|   2. plein ecran sans barre d'adresse — c'est `display: standalone` ;
|   3. demarrage plus rapide — c'est la coquille prechargee par le Service
|      Worker (`SHELL_ASSETS`).
|
| Deux emplacements s'ouvriront plus tard, le JOUR ou leur capacite est livree :
| le partage vers une Boucle, puis les notifications. Pas avant.
|
| ## « Reste connecte » n'y figure pas, et c'est deliberе
|
| Sur iOS, la web app d'ecran d'accueil a son PROPRE conteneur de stockage : la
| session Safari n'est pas heritee, et la personne devra se reconnecter une
| fois. Promettre le contraire serait faux des le premier lancement.
|
*/

// TASK-1805 — toutes les occurrences de « BouclePro » ci-dessous nomment
// L'APPLICATION QU'ON INSTALLE : elles doivent dire « BoucleDev » en local
// (recette Cyril sur un tunnel Cloudflare, 11/11 — le bandeau disait encore
// « Installer BouclePro »), jamais un hostname. Une SEULE lecture, en tete de
// fichier, plutot qu'un appel dans chaque chaine.
$appName = \App\Support\Pwa\PwaAppName::current();

return [

    // ── Ecran plein format ──────────────────────────────────────────────────
    'title' => "Installez {$appName} sur votre téléphone",
    'promise' => 'Vos Boucles, à portée de pouce.',

    'benefits' => [
        'home_screen' => [
            'title' => 'Vos Boucles en un geste',
            'body' => 'Une icône sur votre écran d\'accueil, plus d\'adresse à taper.',
        ],
        'fullscreen' => [
            'title' => 'Tout l\'écran pour vos échanges',
            'body' => 'Sans barre d\'adresse, la conversation respire.',
        ],
        'faster' => [
            'title' => 'Plus rapide au lancement',
            'body' => 'L\'habillage de l\'application reste en mémoire.',
        ],
    ],

    'install' => "Installer {$appName}",
    'continue_in_browser' => 'Continuer dans le navigateur',

    // Sur `/installer`, l'action secondaire n'est PAS un renoncement : la
    // personne a demande a voir cette page, en repartir ne refuse rien.
    // Le libelle doit donc dire RETOUR, pas « continuer sans ».
    'back_to_site' => 'Retour au site',

    // ── Etat apres acceptation ──────────────────────────────────────────────
    //
    // On ne dit JAMAIS « c'est installe » : l'installation se termine hors de
    // notre page, et sur iOS elle n'est meme pas observable. On invite a ouvrir
    // l'icone, ce qui est verifiable par la personne elle-meme.
    'installing_title' => "{$appName} s’installe sur votre téléphone",
    'installing_body' => "Vous êtes encore dans votre navigateur. Retrouvez son icône sur votre écran d’accueil ou dans vos applications, puis ouvrez-la.",
    'installing_note' => "Lors du premier lancement, {$appName} vous proposera d’activer les notifications importantes.",

    // ── Quand `/installer` n'a rien a proposer ───────────────────────────────
    //
    // Le kill-switch `PWA_INSTALL_POLICY=off` n'emet aucun ecran. Mais
    // `/installer` reste une URL : un signet, un lien dans un courriel, une
    // adresse tapee. Sans ces deux chaines la page rendait un ecran VIDE —
    // le meme defaut que la recette du 31/10/2026 a remonte par une autre
    // porte. Une page atteignable doit toujours dire quelque chose.
    'unavailable_title' => 'Installation indisponible',
    'unavailable_body' => "L'installation de {$appName} sur votre appareil n'est pas proposée pour le moment. Le site reste accessible depuis votre navigateur.",

    // ── Instructions par plateforme ─────────────────────────────────────────
    'manual_title' => "Pour installer {$appName}",

    'ios' => [
        'intro' => 'Trois gestes, depuis Safari :',
        'step_share' => 'Touchez le bouton Partager, en bas de l\'écran',
        'step_add' => 'Choisissez « Sur l\'écran d\'accueil »',
        'step_confirm' => "Touchez « Ajouter », puis ouvrez l'icône {$appName}",
        // Honnetete : les libelles des menus iOS changent d'une version a
        // l'autre, et on ne peut pas les connaitre toutes.
        'disclaimer' => 'Selon votre version d\'iOS, les libellés peuvent différer.',
    ],

    // Beaucoup de gens utilisent Chrome sur iPhone. Leur montrer les
    // instructions de Safari serait leur decrire un menu qu'ils n'ont pas.
    'ios_other_browser' => [
        'title' => 'Ouvrez cette page dans Safari',
        'body' => 'Sur iPhone et iPad, l\'installation sur l\'écran d\'accueil passe par Safari.',
    ],

    'generic' => [
        'intro' => 'Depuis le menu de votre navigateur :',
        'step_menu' => 'Ouvrez le menu du navigateur',
        'step_add' => 'Choisissez « Installer l\'application » ou « Ajouter à l\'écran d\'accueil »',
    ],

    // ── Bandeau non bloquant ────────────────────────────────────────────────
    'banner' => [
        'title' => "{$appName} s'installe sur votre téléphone",
        'body' => 'Vos Boucles en un geste, plein écran.',
        'action' => 'Installer',
        'dismiss' => 'Masquer',
    ],

    // ── Accessibilite ───────────────────────────────────────────────────────
    'dialog_label' => "Installer l'application {$appName}",
    'close' => 'Fermer',

    /*
     * TASK-1745 — la proposition du PREMIER LANCEMENT installe.
     *
     * Le corps nomme les evenements reellement couverts par le catalogue
     * (T1744) : invitation, ajout a une Boucle, demande acceptee. Promettre
     * plus serait creux, promettre moins ferait refuser a tort.
     *
     * Et il dit comment revenir en arriere AVANT de demander : une
     * permission qu'on sait reversible se refuse moins par reflexe.
     */
    'first_run_title' => "Activer les notifications ?",
    'first_run_body' => "{$appName} vous préviendra lorsqu’on vous invite, qu’on vous ajoute à une Boucle ou qu’on accepte votre demande. Vous pourrez changer d’avis à tout moment dans vos réglages.",
    'first_run_accept' => "Activer les notifications",
    'first_run_later' => "Plus tard",
    'first_run_failed' => "Les notifications n’ont pas pu être activées. Vous pourrez réessayer depuis vos réglages.",

    /*
     * TASK-1745, recette du 03/11 — les trois états de permission sont trois
     * parcours, et chacun a son corps de texte.
     *
     * `granted` : l'autorisation système est déjà là (accordée autrefois, ou
     * depuis les contrôles du site — Chrome Android 155+). Le texte doit dire
     * qu'AUCUNE demande ne repartira : c'est ce qui fait cliquer.
     *
     * `denied` : aucun bouton ne peut rouvrir une permission refusée — on
     * EXPLIQUE le chemin, sans relancer une demande impossible. Le texte
     * nomme le geste Android réel : les réglages du site.
     */
    'first_run_granted_body' => "Votre autorisation est déjà accordée : il ne reste qu’à activer cet appareil, sans nouvelle demande.",
    'first_run_denied_body' => "Les notifications sont bloquées pour {$appName} dans votre navigateur. Pour les rouvrir : réglages du site (icône à côté de l’adresse, ou Paramètres › Notifications), autorisez les notifications, puis revenez ici.",
    'first_run_understood' => "Compris",

    // TASK-1822 — l'abonnement existe, la permission est peut-être accordée,
    // mais il a été créé avec une clé de sécurité qui n'a plus cours : le
    // navigateur jette les messages sans rien afficher. Ne JAMAIS présenter
    // cela comme « activez les notifications » — la personne les croyait
    // actives, et elles l'étaient.
    'first_run_stale_key_body' => "Cet appareil était abonné avec une clé de sécurité qui n’a plus cours : les notifications ne lui parviennent plus, même si elles semblent activées. Une réactivation suffit à rétablir la réception.",
    'first_run_stale_key_action' => "Réactiver les notifications",

    /*
     * TASK-1745, recette du 03/11 — les gestes NUMEROTES de la carte de
     * confirmation. Une liste se parcourt du coin de l'oeil ; une phrase
     * continue oblige a tout lire pour trouver ce qu'on doit faire.
     *
     * Le dernier n'est pas un geste mais une ANNONCE, et il a sa variante
     * `_done` : une reinstallation sur un appareil deja abonne ne doit pas
     * promettre une question qui ne sera pas posee.
     */
    'installing_step_wait' => "Android termine l’installation, cela prend quelques secondes.",
    'installing_step_icon' => "Retrouvez l’icône {$appName} sur votre écran d’accueil ou dans vos applications.",
    'installing_step_open' => "Ouvrez-la : vous êtes encore dans votre navigateur, pas dans l’application.",
    'installing_step_notifications' => "Au premier lancement, {$appName} vous proposera d’activer les notifications importantes.",
    'installing_step_notifications_done' => "Les notifications sont déjà actives sur cet appareil : rien à refaire.",

    /*
    | TASK-1878 — console « Diagnostic PWA ». Chaque anomalie porte une
    | explication et, quand c'est possible, une action. Aucun texte ne promet
    | ce qu'une mesure web ne peut pas prouver.
    */
    'diagnostic' => [
        'title' => 'Diagnostic PWA',
        'intro' => "Cette page mesure l’état de {$appName} sur cet appareil. Elle ne modifie rien : ni réglage, ni Service Worker, ni partage en attente.",
        'link_from_device' => 'Diagnostic PWA complet',
        'back_device' => '← Réglages sur cet appareil',
        'auto_heading' => 'Mesures automatiques',
        'manual_heading' => 'Vérifications manuelles',
        'limits' => "Limites : être ouvert dans l’application installée ne prouve pas qu’Android a enregistré {$appName} dans son menu Partager. Une simulation technique ne remplace pas un test réel sur Android. Un envoi accepté par le fournisseur n’est pas une preuve de réception.",
        'measuring' => 'Mesure en cours…',

        'status' => [
            'ok' => 'OK',
            'fail' => 'ÉCHEC',
            'unverifiable' => 'NON VÉRIFIABLE',
            'untested' => 'NON TESTÉ',
        ],
        'yes' => 'Oui',
        'no' => 'Non',
        'none' => 'aucun',
        'no_answer' => 'pas de réponse',

        'sections' => [
            'installation' => 'Installation',
            'service_worker' => 'Service Worker',
            'notifications' => 'Notifications',
            'sharing' => 'Partage Android',
        ],

        'rows' => [
            'inst_origin' => 'Origine',
            'inst_secure' => 'Connexion sécurisée (HTTPS)',
            'inst_standalone' => 'Ouvert dans l’application installée',
            'inst_manifest_link' => 'Manifeste déclaré dans la page',
            'inst_manifest_valid' => 'Manifeste servi et valide',
            'inst_name' => 'Nom de l’application',
            'inst_support' => 'Fonctions PWA du navigateur',
            'sw_registration' => 'Service Worker enregistré',
            'sw_controller' => 'Service Worker contrôlant la page',
            'sw_scope' => 'Portée (scope)',
            'sw_version' => 'Version active',
            'sw_waiting' => 'Mise à jour en attente',
            'push_permission' => 'Autorisation des notifications',
            'push_subscription' => 'Abonnement de cet appareil',
            'push_server' => 'Reconnu par le serveur',
            'push_key' => 'Clé VAPID de l’abonnement',
            'push_events' => 'Événements ouverts au Push',
            'push_local' => 'Test d’affichage local',
            'push_simulate' => 'Simulation de réception (Service Worker)',
            'push_real' => 'Test Web Push réel',
            'share_standalone' => 'Ouvert dans l’application installée',
            'share_declared' => "Partage vers {$appName} déclaré",
            'share_params' => 'Méthode GET et paramètres url, text, title',
            'share_server' => 'Déclaration attendue par le serveur',
            'share_route' => 'Parcours de réception (/partage → choix de Boucle)',
            'share_simulation' => 'Simulation technique du partage (sans Android)',
            'share_pending' => 'Partage reçu en attente',
            'share_native' => 'Présence dans le menu Partager d’Android',
        ],

        'values' => [
            'name_expected' => '%s (attendu : %s)',
            'sw_version' => '%s (publiée : %s)',
            'support' => 'Service Worker %s · Push %s · Notifications %s · Presse-papiers %s',
            'manifest_ok' => 'lu, complet',
            'events' => '%s événement(s)',
            'simulation_ok' => 'un partage type « titre + lien » est bien compris',
            'not_run' => 'pas encore lancé',
            'real_sent' => 'envoi accepté par le fournisseur — réception à confirmer manuellement',
            'native_manual' => 'à vérifier à la main (test ci-dessous)',
        ],

        'help' => [
            'inst_secure' => 'Sans HTTPS, ni Service Worker ni notifications ne fonctionnent. Ouvrez l’adresse en https://.',
            'inst_standalone' => "Cette page est ouverte dans le navigateur. Pour diagnostiquer l’application, ouvrez {$appName} depuis son icône, puis revenez ici (Notifications → Réglages sur cet appareil → Diagnostic PWA complet).",
            'inst_manifest_link' => 'La page ne déclare aucun manifeste : le navigateur ne peut pas proposer l’installation. À signaler à l’équipe avec ce rapport.',
            'inst_manifest_valid' => 'Le manifeste n’a pas pu être lu ou est incomplet (%s). À signaler à l’équipe avec ce rapport.',
            'inst_name' => 'Le nom servi diffère du nom attendu : l’icône installée correspond peut-être à une autre version. Désinstallez-la, puis réinstallez depuis cette adresse.',
            'inst_support' => 'Ce navigateur ne prend pas en charge toutes les fonctions nécessaires. Sur Android, utilisez Chrome à jour.',
            'sw_registration' => 'Aucun Service Worker : rechargez la page. S’il reste absent, vérifiez que vous n’êtes pas en navigation privée.',
            'sw_controller' => 'La page n’est pas encore contrôlée (premier chargement ou rechargement forcé). Rechargez normalement la page.',
            'sw_scope' => 'La portée ne couvre pas tout le site : notifications et partage peuvent s’ouvrir hors de l’application. À signaler à l’équipe.',
            'sw_version' => "L’exemplaire actif est plus ancien que celui publié. Fermez toutes les fenêtres et l’application {$appName}, puis rouvrez-la.",
            'sw_version_silent' => "L’exemplaire actif ne répond pas au diagnostic : il est antérieur à ce protocole. Fermez toutes les fenêtres et l’application {$appName}, puis rouvrez-la.",
            'sw_waiting' => "Une nouvelle version attend que toutes les fenêtres {$appName} soient fermées. Fermez-les toutes, application comprise, puis rouvrez. Cette page ne force pas la mise à jour.",
            'push_permission' => "Les notifications sont bloquées. Android : Paramètres → Applications → {$appName} (ou Chrome) → Notifications → Autoriser.",
            'push_permission_default' => 'Autorisation pas encore demandée : utilisez « Activer » dans Réglages sur cet appareil.',
            'push_subscription' => 'Aucun abonnement : activez les notifications dans Réglages sur cet appareil.',
            'push_server' => 'Le serveur ne connaît pas cet abonnement : désactivez puis réactivez les notifications dans Réglages sur cet appareil.',
            'push_key' => 'L’abonnement a été créé avec une ancienne clé : les notifications seront rejetées en silence. Désactivez puis réactivez.',
            'push_key_hidden' => 'Ce navigateur ne montre pas la clé de l’abonnement : la concordance ne peut pas être mesurée.',
            'push_events' => 'Aucun événement n’est coché pour le canal Push : rien ne sera envoyé. Cochez-en dans vos préférences.',
            'push_local' => 'L’affichage local échoue : la cause est sur l’appareil (permission, notifications coupées dans Android, mode Ne pas déranger).',
            'push_simulate' => 'Le Service Worker n’affiche pas une charge simulée : fermez puis rouvrez l’application pour charger la version publiée.',
            'push_real' => 'L’envoi réel échoue : suivez le message affiché par le test, puis réactivez les notifications si besoin.',
            'share_standalone' => "Ouvrez {$appName} depuis son icône pour tester le partage dans les conditions réelles.",
            'share_declared' => "Le manifeste servi ne déclare pas le partage vers /partage : Android ne peut pas proposer {$appName}. À signaler à l’équipe.",
            'share_params' => 'La déclaration de partage est incomplète (méthode ou paramètres). À signaler à l’équipe.',
            'share_server' => 'Le manifeste de référence du serveur ne correspond plus à la déclaration attendue. À signaler à l’équipe.',
            'share_route' => 'Le parcours de réception du partage est indisponible sur ce serveur. À signaler à l’équipe.',
            'share_simulation' => 'Le serveur ne sait plus extraire un lien d’un partage. À signaler à l’équipe.',
            'share_pending' => 'Un partage reçu attend : il s’affichera sur la page de choix de Boucle. Ce diagnostic ne l’a pas modifié.',
            'share_native' => 'Aucune API web ne permet de le vérifier. Faites le test manuel ci-dessous. Dans Chrome Android, l’adresse chrome://webapks liste les applications installées.',
            'manual_failed' => 'Diagnostic manuel en échec : voir l’explication ci-dessous.',
        ],

        'manual' => [
            'intro' => 'Ces points ne se mesurent pas depuis une page web. Faites le test, puis répondez : vos réponses restent sur cet appareil et entrent dans le rapport.',
            'steps_heading' => 'Tester le partage Android',
            'steps' => [
                "Installez {$appName} depuis Chrome (menu ⋮ → Installer l’application), puis ouvrez-la depuis son icône.",
                'Dans Chrome, ouvrez une page web, puis menu ⋮ → Partager.',
                "Cherchez {$appName} dans la liste (parfois sous « Plus »).",
                "Touchez {$appName} : la page « Partager dans une Boucle » doit afficher le lien.",
                'Vérifiez que vous pouvez choisir une Boucle. Pour un simple essai, ne publiez pas : revenez en arrière.',
                'Revenez ici et répondez aux questions.',
            ],
            'open_choose' => 'Ouvrir la page de choix de Boucle (sans publier)',
            'answer_yes' => 'Oui',
            'answer_no' => 'Non',
            'answer_untested' => 'Pas testé',
            'questions' => [
                'm_push_received' => 'J’ai reçu la notification du test Web Push réel',
                'm_share_menu' => "{$appName} apparaît dans le menu Partager d’Android",
                'm_share_transmitted' => 'À l’ouverture, le lien partagé est affiché',
                'm_share_choose' => 'La page de choix de Boucle s’affiche',
                'm_share_loop' => 'Je peux choisir une Boucle autorisée',
            ],
            'help' => [
                'm_push_received' => "Envoi accepté mais rien reçu : vérifiez les notifications Android de {$appName} et de Chrome, le mode Ne pas déranger et l’économiseur de batterie. Comparez avec la simulation de réception : si elle s’affiche, la panne est dans la livraison.",
                'm_share_menu' => "Installée comme simple raccourci (et non comme application), {$appName} n’est pas enregistrée comme cible de partage : désinstallez-la, puis réinstallez depuis Chrome (« Installer l’application »). Contrôlez dans chrome://webapks. Le menu peut aussi la ranger sous « Plus ».",
                'm_share_transmitted' => 'L’application source n’a peut-être transmis qu’un texte sans lien. Réessayez depuis Chrome (Partager une page web).',
                'm_share_choose' => 'Notez l’écran obtenu (connexion demandée ? erreur ?) et joignez ce rapport.',
                'm_share_loop' => 'Aucune Boucle proposée : vous n’êtes membre d’aucune Boucle où la publication est autorisée.',
            ],
        ],

        'report' => [
            'copy' => 'Copier le rapport de diagnostic',
            'copied' => 'Rapport copié. Collez-le dans votre message à l’équipe.',
            'copy_failed' => 'Copie impossible ici : sélectionnez le texte ci-dessous et copiez-le.',
            'title' => "Rapport de diagnostic PWA {$appName}",
            'date' => 'Date',
            'environment' => 'Environnement',
            'version' => 'Version applicative',
            'platform' => 'Plateforme',
            'install_mode' => 'Mode d’installation détecté',
            'mode_standalone' => 'application installée (standalone)',
            'mode_browser' => 'navigateur',
            'causes' => 'Causes probables et étapes suivantes',
            'no_cause' => 'Aucune anomalie mesurée.',
            'unknown' => 'inconnue',
        ],
    ],
];
