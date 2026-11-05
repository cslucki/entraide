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
    'proposer_autre_temps' => 'Suggest a different time',
    'conversation_d_abord' => 'The exchange starts with a conversation — you will agree on the time together.',
    'deja_en_discussion' => 'You are already in a discussion about this listing.',
    'discuter_avec' => 'Chat with :prenom',
    'echange_en_cours' => 'Your offer from :date is in progress — :temps, awaiting a reply.',
    'csv' => [
        'fichier' => 'mutual-aid',
        'entetes' => ['Date', 'Direction', 'Exchange', 'With', 'Time', 'Points', 'Status'],
        'aide_recue' => 'Help received',
        'aide_apportee' => 'Help given',
    ],
    'temps' => [
        'minutes' => ':n min',
        'heures' => ':n h',
        'heures_minutes' => ':h h :m',
        // Le double affichage retenu par Cyril (Q8, 05/11).
        'double' => '{0}:temps · 0 pts|{1}:temps · :points pt|[2,*]:temps · :points pts',
        // Le coup de main offert (§9.3) : jamais « 0 min ».
        'offert' => 'Offered 🎁',
        'solde' => 'Your available time: :temps',
        // Le sous-titre du grand nombre : c'est lui qui porte les points, pour
        // que le TEMPS reste la premiere chose qu'on lit (arbitrage Q8).
        'sous_titre' => '{1}of shared time · :points pt|[2,*]of shared time · :points pts',
        'sous_titre_offert' => 'a helping hand, offered',
    ],
];
