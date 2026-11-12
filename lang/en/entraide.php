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
    'conversation_d_abord' => 'Mutual aid starts with a conversation — you will agree on the time together.',
    'deja_en_discussion' => 'You are already in a discussion about this listing.',
    'discuter_avec' => 'Chat with :prenom',
    'echange_en_cours' => 'Your offer from :date is in progress — :temps, awaiting a reply.',
    // TASK-1754 §3 — les toasts, desormais traduits.
    'flash' => [
        'soi_meme' => 'You cannot start a mutual aid with yourself.',
        'solde_insuffisant' => 'You do not have enough available time for this mutual aid.',
        'proposition_envoyee' => 'Offer sent!',
        'echange_accepte' => 'Help accepted.',
        'echange_decline' => 'Offer declined.',
        'ajustement_impossible' => 'The time can only be adjusted while the offer is pending.',
        'temps_ajuste' => 'Time adjusted.',
        'echange_annule' => 'Mutual aid cancelled.',
        'aide_recue' => 'Noted — :prenom now needs to confirm.',
        'deja_traite' => 'This mutual aid has already been handled.',
        'echange_termine' => 'Mutual aid completed — thank you!',
        // TASK-1806 — the mutual aid IS closed and the points ARE transferred;
        // only the review could not be written. Say so.
        'echange_termine_avis_non_enregistre' => 'Mutual aid completed and points transferred. Your review could not be saved: you can post it again below.',
        'contestation_impossible' => 'This mutual aid cannot be reopened.',
        'pas_encore_termine' => 'Noted: the mutual aid is active again.',
    ],
    // TASK-1809 (Cyril's fix, 12/11) — explicit Feed sharing (separate
    // link, after closure) is removed: sharing is now AUTOMATIC as soon as
    // the review is submitted (`EvaluationEntraide::enregistrer()`).

    'atelier' => [
        'complet' => 'This workshop is full.',
        'places_restantes' => '{0}Full|{1}1 seat left|[2,*]:count seats left',
        'groupe' => 'Group workshop · :n people maximum',
        'champ_label' => 'Offer this as a workshop',
        'champ_aide' => 'Leave empty for one-to-one help. From 2 to 6 people for a workshop — the announced time is shared between participants.',
        'champ_capacite' => 'Up to',
        'champ_plancher' => 'From',
        'apercu_avant' => 'Each participant will pay at most',
        'apercu_apres' => 'minutes — less if more people join.',
        'prix_max' => 'at most :temps per person',
        'temps_non_negociable' => 'A workshop’s time is shared between participants: it cannot be negotiated seat by seat.',
        'carte' => 'workshop · :temps / person',
        'champ_individuel' => 'One-to-one help',
    ],
    'csv' => [
        'fichier' => 'mutual-aid',
        'entetes' => ['Date', 'Direction', 'Mutual aid', 'With', 'Time', 'Points', 'Status'],
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

    // TASK-1786 — "Comments and suggestions" under a mutual aid listing.
    'commentaires' => [
        'titre' => 'Comments and suggestions',
        'invite_proposition' => 'Do you have a suggestion or a question to improve this mutual aid offer? Share it with its author.',
        'invite_demande' => 'Do you have a suggestion or a question to improve this help request? Share it with its author.',
        'placeholder' => 'Your suggestion or question…',
        'publier' => 'Post my comment',
        'total' => '{0}no comments|{1}1 comment|[2,*]:count comments',
        'vide' => 'No comments yet. Be the first to help.',
        'publie' => 'Your comment has been posted.',
        'voir_mon_commentaire' => 'View my comment',
        'supprimer' => 'Delete',
        'confirmer_suppression' => 'Delete this comment?',
        'fermee_proposition' => 'This offer is no longer active: comments remain readable.',
        'fermee_demande' => 'This request is closed: comments remain readable.',
        'trop_rapide' => 'You are posting too fast. Try again in :secondes seconds.',
        'type_proposition' => 'mutual aid offer',
        'type_demande' => 'help request',
    ],
];
