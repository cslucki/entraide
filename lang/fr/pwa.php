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
];
