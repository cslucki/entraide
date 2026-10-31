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

return [

    // ── Ecran plein format ──────────────────────────────────────────────────
    'title' => 'Installez BouclePro sur votre téléphone',
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

    'install' => 'Installer BouclePro',
    'continue_in_browser' => 'Continuer dans le navigateur',

    // ── Etat apres acceptation ──────────────────────────────────────────────
    //
    // On ne dit JAMAIS « c'est installe » : l'installation se termine hors de
    // notre page, et sur iOS elle n'est meme pas observable. On invite a ouvrir
    // l'icone, ce qui est verifiable par la personne elle-meme.
    'installing_title' => 'Presque terminé',
    'installing_body' => 'Ouvrez BouclePro depuis votre écran d\'accueil.',

    // ── Instructions par plateforme ─────────────────────────────────────────
    'manual_title' => 'Pour installer BouclePro',

    'ios' => [
        'intro' => 'Trois gestes, depuis Safari :',
        'step_share' => 'Touchez le bouton Partager, en bas de l\'écran',
        'step_add' => 'Choisissez « Sur l\'écran d\'accueil »',
        'step_confirm' => 'Touchez « Ajouter », puis ouvrez l\'icône BouclePro',
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
        'title' => 'BouclePro s\'installe sur votre téléphone',
        'body' => 'Vos Boucles en un geste, plein écran.',
        'action' => 'Installer',
        'dismiss' => 'Masquer',
    ],

    // ── Accessibilite ───────────────────────────────────────────────────────
    'dialog_label' => 'Installer l\'application BouclePro',
    'close' => 'Fermer',
];
