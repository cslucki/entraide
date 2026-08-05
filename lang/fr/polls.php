<?php

return [

    // ── Liste ───────────────────────────────────────────────────────────────
    'title' => 'Sondages',
    'create' => 'Créer un sondage',
    'open_section' => 'En cours',
    'closed_section' => 'Clôturés',
    'empty' => 'Aucun sondage pour le moment.',
    'empty_hint' => 'Pose une question aux membres : ils répondront, et le résultat se lira d\'un coup d\'œil.',
    'by' => 'Par :name',
    'participants' => ':count participant(s)',
    'status_open' => 'Ouvert',
    'status_closed' => 'Clôturé',
    'type_single' => 'Choix unique',
    'type_multiple' => 'Choix multiple',
    'unknown_voter' => 'Membre inconnu',
    'unknown_author' => 'Auteur inconnu',

    // ── Création et modification ────────────────────────────────────────────
    'form_title_create' => 'Poser une question',
    'form_title_edit' => 'Modifier le sondage',
    'question' => 'Question',
    'question_placeholder' => 'Quelle question poses-tu ?',
    'description' => 'Précision (facultatif)',
    'description_placeholder' => 'Une phrase de contexte, si c\'est utile.',
    'selection_type' => 'Réponses',
    'options' => 'Réponses possibles',
    'option_placeholder' => 'Réponse :number',
    'add_option' => 'Ajouter une réponse',
    'remove_option' => 'Retirer cette réponse',
    'publish' => 'Publier',
    'save' => 'Enregistrer',
    'cancel' => 'Annuler',
    'edit' => 'Modifier',
    'edit_locked' => 'Ce sondage a reçu des réponses : il ne peut plus être modifié.',

    // ── Vote ────────────────────────────────────────────────────────────────
    'vote' => 'Voter',
    'change_vote' => 'Modifier mon vote',
    'your_vote' => 'Ton vote :',
    'voted_confirmation' => 'Vote enregistré.',
    'results_after_vote' => 'Les résultats s\'affichent une fois que tu as voté.',

    // ── Résultats ───────────────────────────────────────────────────────────
    'results' => 'Résultats',
    'result_line' => ':votes vote(s) · :percentage %',
    'total_participants' => ':count personne(s) se sont prononcées.',
    'detail_show' => 'Voir qui a répondu quoi',
    'detail_hide' => 'Masquer le détail',
    'detail_empty' => 'Personne n\'a encore répondu.',

    // ── Clôture et suppression ──────────────────────────────────────────────
    'close' => 'Clôturer',
    'close_confirm_title' => 'Clôturer ce sondage ?',
    'close_confirm_body' => 'Les membres ne pourront plus voter ni modifier leur vote. Les résultats sont conservés.',
    'close_confirm_cta' => 'Clôturer',
    'closed_notice' => 'Clôturé le :date',
    'delete' => 'Supprimer',
    'delete_confirm_title' => 'Supprimer ce sondage ?',
    'delete_confirm_body' => 'Personne n\'a encore répondu : rien ne sera perdu. Cette action est définitive.',
    'delete_confirm_cta' => 'Supprimer',
    'deleted' => 'Sondage supprimé.',

    // ── Lecture seule ───────────────────────────────────────────────────────
    'read_only' => 'Cette Boucle est archivée : les sondages sont en lecture seule.',

    // ── Erreurs ─────────────────────────────────────────────────────────────
    'error_not_allowed' => 'Tu n\'as pas le droit de faire cela.',
    'error_not_member' => 'Il faut être membre actif de la Boucle.',
    'error_question_required' => 'La question ne peut pas être vide.',
    'error_selection_type' => 'Mode de réponse inconnu.',
    'error_min_options' => 'Il faut au moins deux réponses possibles.',
    'error_max_options' => 'Dix réponses au maximum.',
    'error_duplicate_option' => 'Deux réponses sont identiques.',
    'error_closed' => 'Ce sondage est clôturé.',
    'error_already_voted' => 'Ce sondage a déjà reçu des réponses : il ne peut plus être modifié.',
    'error_no_choice' => 'Choisis au moins une réponse.',
    'error_single_choice' => 'Ce sondage n\'accepte qu\'une seule réponse.',
    'error_unknown_option' => 'Cette réponse n\'appartient pas à ce sondage.',
    'error_delete_voted' => 'Ce sondage a reçu des réponses : il se clôture, il ne se supprime pas.',

    // ── ChatLoop ────────────────────────────────────────────────────────────
    'chat_created' => 'Un nouveau sondage a été publié : :question',
    'chat_closed' => 'Le sondage « :question » est clôturé.',
    'chat_open_card' => 'Ouvrir les sondages',
];
