<?php

return [

    // ── Liste et agenda ─────────────────────────────────────────────────────
    'title' => 'Événements',
    'create' => 'Proposer une rencontre',
    'upcoming' => 'À venir',
    'past' => 'Passés et annulés',
    'empty' => 'Aucune rencontre pour le moment.',
    'empty_hint' => 'Propose une rencontre : les membres diront s\'ils viennent.',
    'view_list' => 'Liste',
    'view_calendar' => 'Calendrier',
    'view_week' => 'Semaine',
    'view_month' => 'Mois',
    'previous_month' => 'Mois précédent',
    'next_month' => 'Mois suivant',
    'today' => 'Aujourd\'hui',
    'by' => 'Par :name',
    'unknown_person' => 'Membre inconnu',
    'unknown_author' => 'Auteur inconnu',

    // ── Formats et statuts ──────────────────────────────────────────────────
    'format' => 'Format',
    'format_in_person' => 'Sur place',
    'format_online' => 'En ligne',
    'format_hybrid' => 'Hybride',
    'status_cancelled' => 'Annulé',
    'scope_loop' => 'Cette Boucle',
    'scope_organization' => 'Toute l\'Organization',
    'scope' => 'Qui peut voir',
    'from_loop' => 'Boucle : :name',

    // ── Formulaire ──────────────────────────────────────────────────────────
    'form_title_create' => 'Proposer une rencontre',
    'form_title_edit' => 'Modifier la rencontre',
    'event_title' => 'Titre',
    'event_title_placeholder' => 'De quoi s\'agit-il ?',
    'description' => 'Description (facultatif)',
    'description_placeholder' => 'Ordre du jour, précisions utiles…',
    'starts_at' => 'Début',
    'ends_at' => 'Fin (facultatif)',
    // TASK-1802 (demande Cyril) — la fin se choisit comme une duree relative
    // au debut, plus simple qu'une minute absolue.
    'duration' => 'Durée',
    'duration_none' => 'Sans heure de fin',
    'duration_30' => '30 minutes',
    'duration_60' => '1 heure',
    'duration_90' => '1 h 30',
    'duration_120' => '2 heures',
    'duration_180' => '3 heures',
    'duration_240' => '4 heures',
    'duration_480' => 'Journée (8 heures)',
    'duration_custom' => 'Dates personnalisées…',
    'duration_crosses_day' => 'Cette durée se termine un autre jour : vérifiez la date de fin.',
    'timezone' => 'Fuseau horaire',
    'timezone_hint' => 'Détecté depuis votre appareil. Vérifiez-le si la rencontre a lieu dans une autre zone.',
    'location' => 'Lieu',
    'location_placeholder' => 'Adresse ou nom du lieu',
    'meeting_url' => 'Lien de connexion',
    'meeting_url_placeholder' => 'https://…',
    'publish' => 'Publier',
    'save' => 'Enregistrer',
    'cancel' => 'Annuler',
    'edit' => 'Modifier',
    'edit_warning' => 'Des membres ont déjà répondu. Les modifications conserveront leurs réponses.',

    // ── Réponses ────────────────────────────────────────────────────────────
    'going' => 'Je participe',
    'maybe' => 'Peut-être',
    'not_going' => 'Je ne participe pas',
    'attendance_label' => 'Présences',
    'going_short' => 'Participent',
    'maybe_short' => 'Peut-être',
    'not_going_short' => 'Absents',
    'your_response' => 'Ta réponse :',
    'respond_menu_label' => 'Répondre',
    'response_saved' => 'Réponse enregistrée.',
    'attendees_show' => 'Voir qui vient',
    'attendees_hide' => 'Masquer',
    'attendees_empty' => 'Personne n\'a encore répondu.',
    'going_count' => ':count participant(s)',

    // ── Annulation, suppression, portée ─────────────────────────────────────
    'cancel_event' => 'Annuler l\'événement',
    'cancel_confirm_title' => 'Annuler cet événement ?',
    'cancel_confirm_body' => 'Il restera visible dans l\'historique et les participants ne pourront plus modifier leur réponse.',
    'cancel_confirm_cta' => 'Annuler l\'événement',
    'cancelled_notice' => 'Annulé le :date',
    'delete' => 'Supprimer',
    'delete_confirm_title' => 'Supprimer cet événement ?',
    'delete_confirm_body' => 'Personne n\'a encore répondu : rien ne sera perdu. Cette action est définitive.',
    'delete_confirm_cta' => 'Supprimer',
    'deleted' => 'Événement supprimé.',
    'publish_org_title' => 'Rendre visible par toute l\'Organization ?',
    'publish_org_body' => 'Cet événement deviendra visible par tous les membres actifs de cette Organization.',
    'publish_org_cta' => 'Rendre visible',
    'restrict_loop_title' => 'Réserver à cette Boucle ?',
    'restrict_loop_body' => 'Les membres de l\'Organization qui ne font pas partie de cette Boucle perdront l\'accès à cet événement. Leurs réponses déjà données sont conservées.',
    'restrict_loop_cta' => 'Réserver à la Boucle',
    'private_loop_notice' => 'Cette Boucle est privée : ses événements y restent.',

    // ── Agenda Organization ─────────────────────────────────────────────────
    'agenda_title' => 'Agenda',
    'agenda_empty' => 'Aucune rencontre à afficher.',

    // TASK-1706 — organiser depuis l'agenda. Cyril : « il faut donc passer par
    // une boucle. Ce n'est pas pratique. »
    'agenda_create_button' => 'Créer un évènement',
    'agenda_create_title' => 'Nouvelle rencontre',
    'agenda_create_intro' => 'La rencontre appartiendra à la Boucle que vous choisissez : c\'est elle qui en réunit les participants.',
    'agenda_create_loop' => 'Dans quelle Boucle ?',
    'agenda_create_visibility' => 'Qui peut la voir ?',
    'agenda_create_visibility_loop' => 'Les membres de la Boucle',
    'agenda_create_visibility_organization' => 'Toute l\'Organization',
    'created' => 'La rencontre est créée.',

    // Les TROIS refus. Ils ne se confondent pas : chacun dit un geste
    // different a faire, et se replier sur un message generique afficherait un
    // texte FAUX pour deux personnes sur trois.
    'no_loop_refus' => 'Il faut être membre d\'une Boucle pour créer un évènement.',
    'no_loop_refus_cta' => 'Voir les Boucles',
    'role_refus' => 'Vous devez avoir le rôle de propriétaire ou animateur d\'une boucle pour pouvoir créer un nouvel évènement.',
    'card_refus' => 'Aucune de vos Boucles n\'a l\'outil « Événements » activé. Activez-le dans les réglages d\'une Boucle pour y organiser des rencontres.',
    'filter_all_loops' => 'Toutes les Boucles',
    'filter_all_formats' => 'Tous les formats',
    'filter_upcoming' => 'À venir',
    'filter_past' => 'Passés',
    'open_loop' => 'Ouvrir la Boucle',

    // ── Lecture seule ───────────────────────────────────────────────────────
    'read_only' => 'Cette Boucle est archivée : les événements sont en lecture seule.',

    // ── Erreurs ─────────────────────────────────────────────────────────────
    'error_not_allowed' => 'Tu n\'as pas le droit de faire cela.',
    'error_not_member' => 'Il faut être membre actif de la Boucle.',
    'error_title_required' => 'Le titre ne peut pas être vide.',
    'error_format' => 'Format inconnu.',
    'error_timezone' => 'Fuseau horaire inconnu.',
    'error_start_required' => 'Une date de début est nécessaire.',
    'error_date_invalid' => 'Cette date n\'est pas lisible.',
    'error_end_before_start' => 'La fin doit être postérieure au début.',
    'error_location_required' => 'Une rencontre sur place a besoin d\'un lieu.',
    'error_meeting_url_required' => 'Une rencontre en ligne a besoin d\'un lien.',
    'error_meeting_url_invalid' => 'Ce lien n\'est pas une adresse web valide.',
    'error_visibility' => 'Portée inconnue.',
    'error_private_loop' => 'Une Boucle privée ne peut pas publier à l\'Organization.',
    'error_response' => 'Réponse inconnue.',
    'error_cancelled' => 'Cet événement est annulé.',
    'error_delete_answered' => 'Des membres ont répondu : cet événement s\'annule, il ne se supprime pas.',

    // ── ChatLoop ────────────────────────────────────────────────────────────
    // TASK-1802 (demande Cyril) — « il faut ajouter date et heure » sur le
    // message de creation. `:date` est deja formatee (fuseau de l'Evenement),
    // jamais une date brute a interpreter dans la vue.
    'chat_created' => 'Un nouvel événement a été proposé : :title (:date)',
    // TASK-1802 (suite, demande Cyril) — « on ne voit pas la date de fin de
    // l'évènement (ou la durée si c'est moins d'un jour d'écart) ». Deux
    // formes de plus, choisies par `bodyPourNouvelEvenement()` selon que la
    // fin tombe le MEME jour (durée) ou un AUTRE jour (date complete).
    'chat_created_with_duration' => 'Un nouvel événement a été proposé : :title (:date, durée :duree)',
    'chat_created_with_end' => 'Un nouvel événement a été proposé : :title (du :date au :date_fin)',
    // Le lien de connexion n'est PLUS ici : texte echappe par `{{ }}`, il
    // n'etait jamais cliquable. La vue le rend desormais en `<a href>` a
    // partir de `metadata.meeting_url` (demande explicite de Cyril).
    'duration_format_hours_minutes' => ':h h :m',
    'duration_format_hours' => ':h h',
    'duration_format_minutes' => ':m min',
    'chat_updated' => 'L\'événement « :title » a été mis à jour.',
    'chat_cancelled' => 'L\'événement « :title » a été annulé.',
    'chat_open_card' => 'Ouvrir l\'agenda',
    'chat_join_link' => 'Rejoindre',

    // TASK-1802 (suite, demande Cyril) — « juste un bouton pour participer
    // et un bouton pour ajouter à son agenda [...] si on ne peut pas faire
    // un truc compatible sur tout [...] pop-up alpine qui permet de choisir
    // le type d'agenda ». Aucune API web ne couvre Google/Outlook/Yahoo ET
    // Apple/le reste a la fois — d'ou le choix, pas une limitation evitee.
    'add_to_calendar' => 'Ajouter à l\'agenda',
    // TASK-1802 (suite, demande Cyril) — « fait deux boutons : oui et
    // interessé [...] pour "ajouter à l'agenda", tu mets l'icône et juste
    // le verbe "ajouter" ». Cles DEDIEES a ce bouton compact — ne jamais
    // reutiliser `events.going`/`events.maybe` ('Je participe'/'Peut-être')
    // ici : elles servent aussi le bouton de l'agenda plein format
    // (`loops/agenda.blade.php`), qui n'a pas ete touche par cette demande.
    'rsvp_yes' => 'Oui',
    'rsvp_interested' => 'Intéressé',
    'add_cta' => 'Ajouter',
    'calendar_google' => 'Google Agenda',
    'calendar_outlook' => 'Outlook',
    'calendar_yahoo' => 'Yahoo Agenda',
    'calendar_download_ics' => 'Télécharger (.ics)',
];
