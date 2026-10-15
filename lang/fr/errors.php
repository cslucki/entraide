<?php

return [
    '404_title' => 'Page introuvable',
    '404_message' => 'Hmm, cette boucle semble partie en vacances…',
    // TASK-1685 (Q3 + UX MASTER) — le refus inter-tenant DEMONTRE.
    //
    // Statut 404 CONSERVE (doctrine T1601-T1606) et vue de T1606 reutilisee :
    // seule la PRESENTATION devient celle d'une page d'information. Le grand
    // « 404 » laisse place a un cadenas, parce qu'un nombre d'erreur fait lire
    // une panne la ou il s'agit d'une frontiere d'organisation.
    //
    // Aucune lecture de l'Organization visee n'a lieu pour decider de ces
    // textes : un slug INEXISTANT rend exactement les MEMES, donc aucun oracle
    // d'existence n'est cree. C'est la contrepartie assumee — voir le TASK
    // file : la phrase « vous n'etes pas membre de l'organisation » est alors
    // imprecise, mais elle ne REVELE rien, et c'est ce qui compte ici.
    '404_cross_tenant_title' => 'Vous n\'avez pas accès à cet espace',
    '404_cross_tenant_body' => 'Vous n\'êtes pas membre de l\'organisation à laquelle cette page appartient.',
    '404_cross_tenant_hint' => 'Si vous pensez devoir y accéder, demandez une invitation à son administrateur.',
    '404_cross_tenant_back' => 'Retourner à mon organisation',
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
