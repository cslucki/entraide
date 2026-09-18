<?php

return [
    '404_title' => 'Page introuvable',
    '404_message' => 'Hmm, cette boucle semble partie en vacances…',
    '404_search' => 'Que cherchez-vous ?',
    '404_back_home' => 'Retour à l\'accueil',
    // TASK-1483 — le refus explique d'un tableau de bord d'une autre Organization.
    // Le NOM n'apparait que si l'Organization est publique ; sinon la variante
    // neutre, qui ne confirme meme pas son existence.
    'org_member_required_title' => 'Accès réservé aux membres',
    'org_member_required_heading' => 'Cette page est réservée aux membres de :organization.',
    'org_member_required_heading_neutral' => 'Cette page est réservée aux membres de cette organisation.',
    'org_member_required_body' => 'Vous êtes connecté avec un compte qui n\'appartient pas à cette organisation.',
    'org_member_required_own_space' => 'Retourner à mon espace',
    'org_member_required_public_home' => 'Voir l\'accueil de cette organisation',
];
