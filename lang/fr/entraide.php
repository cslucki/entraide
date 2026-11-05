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
