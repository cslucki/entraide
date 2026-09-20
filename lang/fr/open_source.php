<?php

/*
 * TASK-1612 — Open Source Explorer.
 *
 * Aucune de ces chaines ne nomme l'hebergeur du depot autrement que par
 * « GitHub » (la destination du bouton de sortie). Ni l'owner, ni l'URL du
 * depot n'apparaissent ici : ils vivent dans config/open_source.php, cote
 * serveur uniquement.
 */

return [
    'title' => 'BouclePro Core',
    'tagline' => 'Le code source de BouclePro est ouvert et développé publiquement.',

    'github_aria' => 'Code source sur GitHub',

    'badge_public' => 'Public',

    'activity_branch' => 'Branche :branch',
    'activity_branches_one' => ':count branche',
    'activity_branches_other' => ':count branches',
    'activity_tags_one' => ':count tag',
    'activity_tags_other' => ':count tags',
    'activity_commits_one' => ':count commit',
    'activity_commits_other' => ':count commits',
    'activity_last' => 'Dernière activité :ago',

    'structure' => 'Structure du projet',
    'type_dir' => 'Dossier',
    'type_file' => 'Fichier',

    'empty' => 'La racine du dépôt ne contient rien à afficher.',
    'unavailable' => 'Les informations du dépôt ne sont pas accessibles pour le moment. Le code, lui, reste consultable.',
    'stale' => 'Informations issues de la dernière synchronisation.',

    'cta_github' => 'Voir sur GitHub',
    'cta_contribute' => 'Contribuer',

];
