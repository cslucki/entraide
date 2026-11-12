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
    'conversation_d_abord' => 'L’entraide commence par une conversation — vous confirmerez le temps ensemble.',
    'deja_en_discussion' => 'Vous êtes déjà en discussion à ce sujet.',
    'discuter_avec' => 'Discuter avec :prenom',
    'echange_en_cours' => 'Votre proposition du :date est en cours — :temps, en attente de réponse.',
    // TASK-1754 §3 — les toasts. Ils etaient ecrits EN DUR dans le controleur,
    // donc jamais traduits : un anglophone lisait « Prestation declaree
    // terminee ». Et c'est la que vivaient trois des mots les plus visibles du
    // vocabulaire e-commerce.
    'flash' => [
        'soi_meme' => 'Vous ne pouvez pas proposer une entraide avec vous-même.',
        'solde_insuffisant' => 'Votre temps disponible ne suffit pas pour cette entraide.',
        'proposition_envoyee' => 'Proposition envoyée !',
        'echange_accepte' => 'Aide acceptée.',
        'echange_decline' => 'Proposition déclinée.',
        'ajustement_impossible' => 'Le temps ne peut être ajusté que tant que la proposition est en attente.',
        'temps_ajuste' => 'Temps ajusté.',
        'echange_annule' => 'Entraide annulée.',
        'aide_recue' => 'Merci, c’est noté : :prenom doit maintenant confirmer.',
        'deja_traite' => 'Cette entraide a déjà été traitée.',
        'echange_termine' => 'Entraide terminée — merci !',
        // TASK-1806 (verification MASTER du 11/11) — l'entraide EST close et les
        // points SONT transferes ; seul l'avis n'a pas pu etre ecrit. Le dire,
        // plutot que de laisser croire que le remerciement est parti.
        'echange_termine_avis_non_enregistre' => 'Entraide terminée et points transférés. Votre avis n’a pas pu être enregistré : vous pouvez le redéposer ci-dessous.',
        'contestation_impossible' => 'Cette entraide ne peut pas être relancée.',
        'pas_encore_termine' => 'C’est noté : l’entraide est remise en cours.',
    ],
    // TASK-1809 (correctif Cyril, 12/11) — le partage explicite dans le Flux
    // (lien distinct, apres cloture) est retire : le partage est desormais
    // AUTOMATIQUE des que l'avis est depose (`EvaluationEntraide::
    // enregistrer()`). Les deux libelles qui vivaient ici (`partager`/
    // `partagee`) n'ont plus aucun appelant.

    'atelier' => [
        'complet' => 'Cet atelier est complet.',
        'places_restantes' => '{0}Complet|{1}1 place restante|[2,*]:count places restantes',
        'groupe' => 'Atelier de groupe · :n personnes maximum',
        'champ_label' => 'Proposer cette action sous forme d\'atelier',
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

    // TASK-1786 — « Commentaires et suggestions » sous une annonce d'entraide.
    //
    // Le lexique suit la regle du fichier : des personnes qui s'entraident, et
    // le mot « transaction » banni de l'interface membre. Un commentaire
    // n'engage rien, et les textes le disent plutot que de le supposer.
    'commentaires' => [
        'titre' => 'Commentaires et suggestions',

        // Le message d'accompagnement du CDC §2, adapte au type d'annonce.
        'invite_proposition' => 'Vous avez une suggestion ou une question pour améliorer cette proposition d’entraide ? Partagez-la avec son auteur.',
        'invite_demande' => 'Vous avez une suggestion ou une question pour améliorer cette demande d’aide ? Partagez-la avec son auteur.',

        'placeholder' => 'Votre suggestion ou votre question…',
        'publier' => 'Publier mon commentaire',
        'total' => '{0}aucun commentaire|{1}1 commentaire|[2,*]:count commentaires',
        'vide' => 'Aucun commentaire pour l’instant. Soyez la première personne à aider.',
        'publie' => 'Votre commentaire est publié.',
        'voir_mon_commentaire' => 'Voir mon commentaire',
        'supprimer' => 'Supprimer',
        'confirmer_suppression' => 'Supprimer ce commentaire ?',

        // L'annonce ne prend plus de commentaires : les anciens restent
        // LISIBLES, et on dit pourquoi le formulaire a disparu.
        'fermee_proposition' => 'Cette proposition n’est plus active : les commentaires restent consultables.',
        'fermee_demande' => 'Cette demande est clôturée : les commentaires restent consultables.',

        'trop_rapide' => 'Vous publiez trop vite. Réessayez dans :secondes secondes.',

        // Les deux mots que `keys_named` injecte dans `:type`, cote Centre de
        // notifications. Ils vivent ici parce que c'est le lexique d'entraide.
        'type_proposition' => 'proposition d’entraide',
        'type_demande' => 'demande d’aide',
    ],
];
