<?php

/**
 * TASK-1754 — le lexique d'entraide, en UN seul endroit.
 *
 * Principe du CDC (§3.1) : une seule metaphore partout — des personnes qui
 * s'entraident — et les points presentes comme du TEMPS, jamais comme un prix.
 * Le mot « transaction » est BANNI de l'interface membre ; il reste dans le
 * code, les routes et l'administration, ou il nomme correctement une ligne de
 * base de donnees.
 */
return [
    'proposer_autre_temps' => 'Proposer un autre temps',
    'conversation_d_abord' => 'L’échange commence par une conversation — vous confirmerez le temps ensemble.',
    'deja_en_discussion' => 'Vous êtes déjà en discussion pour cette annonce.',
    'discuter_avec' => 'Discuter avec :prenom',
    'echange_en_cours' => 'Votre proposition du :date est en cours — :temps, en attente de réponse.',
    // TASK-1754 §3 — les toasts. Ils etaient ecrits EN DUR dans le controleur,
    // donc jamais traduits : un anglophone lisait « Prestation declaree
    // terminee ». Et c'est la que vivaient trois des mots les plus visibles du
    // vocabulaire e-commerce.
    'flash' => [
        'soi_meme' => 'Vous ne pouvez pas proposer un échange avec vous-même.',
        'solde_insuffisant' => 'Votre temps disponible ne suffit pas pour cet échange.',
        'proposition_envoyee' => 'Proposition envoyée !',
        'echange_accepte' => 'Échange accepté.',
        'echange_decline' => 'Proposition déclinée.',
        'ajustement_impossible' => 'Le temps ne peut être ajusté que tant que la proposition est en attente.',
        'temps_ajuste' => 'Temps ajusté.',
        'echange_annule' => 'Échange annulé.',
        'aide_recue' => 'Merci, c’est noté : :prenom doit maintenant confirmer.',
        'deja_traite' => 'Cet échange a déjà été traité.',
        'echange_termine' => 'Entraide terminée — merci !',
        'contestation_impossible' => 'Cet échange ne peut pas être relancé.',
        'pas_encore_termine' => 'C’est noté : l’échange est remis en cours.',
    ],
    'flux' => [
        // TASK-1757 §9.5 — dit ce qui sera publie ET ce qui ne le sera pas.
        // Une case « Partager dans le Flux » sans cette precision laisserait
        // croire que le nom du beneficiaire y passe aussi.
        'partager' => 'Partager cette entraide dans le Flux (votre prénom et le temps donné ; le nom de la personne aidée n’apparaît pas)',
    ],

    'atelier' => [
        'complet' => 'Cet atelier est complet.',
        'places_restantes' => '{0}Complet|{1}1 place restante|[2,*]:count places restantes',
        'groupe' => 'Atelier de groupe · :n personnes maximum',
        'champ_label' => 'Pour un groupe ?',
        'champ_aide' => 'Laissez vide pour une aide individuelle. De 2 à 6 personnes pour un atelier — le temps annoncé est réparti entre les participants.',
        'champ_capacite' => 'Jusqu’à',
        'champ_plancher' => 'À partir de',
        'apercu_avant' => 'Chaque participant paiera au maximum',
        'apercu_apres' => 'minutes — moins s’il y a plus de monde.',
        'prix_max' => 'au maximum :temps par personne',
        'temps_non_negociable' => 'Le temps d’un atelier se répartit entre les participants : il ne se négocie pas place par place.',
        'carte' => 'atelier · :temps / pers.',
        'champ_individuel' => 'Aide individuelle',
    ],
    'csv' => [
        'fichier' => 'entraide',
        'entetes' => ['Date', 'Sens', 'Entraide', 'Avec', 'Temps', 'Points', 'Statut'],
        'aide_recue' => 'Aide reçue',
        'aide_apportee' => 'Aide apportée',
    ],
    'temps' => [
        'minutes' => ':n min',
        'heures' => ':n h',
        'heures_minutes' => ':h h :m',
        // Le double affichage retenu par Cyril (Q8, 05/11).
        'double' => '{0}:temps · 0 pt|{1}:temps · :points pt|[2,*]:temps · :points pts',
        // Le coup de main offert (§9.3) : jamais « 0 min ».
        'offert' => 'Offert 🎁',
        'solde' => 'Votre temps disponible : :temps',
        // Le sous-titre du grand nombre : c'est lui qui porte les points, pour
        // que le TEMPS reste la premiere chose qu'on lit (arbitrage Q8).
        'sous_titre' => '{1}de temps partagé · :points pt|[2,*]de temps partagé · :points pts',
        'sous_titre_offert' => 'un coup de main offert',
    ],
];
