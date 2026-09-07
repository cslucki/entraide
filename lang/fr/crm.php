<?php

return [
    // TASK-1414 — pipeline initial d'une Organization, seme UNE fois dans sa
    // locale ; ensuite ce sont ses libelles, qu'elle renomme librement.
    'default_status' => [
        'new' => 'Nouveau',
        'to_contact' => 'À contacter',
        'in_progress' => 'Contact en cours',
        'quote_sent' => 'Devis envoyé',
        'client' => 'Client',
        'lost' => 'Perdu',
    ],
    // TASK-1416 — CRM-4 : la liste « Relations ».
    'title' => 'Relations',
    'subtitle' => 'Vos prospects, participants et clients : où en êtes-vous, et quelle est la prochaine étape.',
    'new_contact' => 'Nouveau contact',
    'save_contact' => 'Enregistrer',
    'add_note' => 'Note',
    'save_note' => 'Ajouter',
    'note_placeholder' => 'Ce qui s\'est dit, ce qui a été convenu…',
    'never_contacted' => 'Jamais',
    'empty' => 'Aucun contact pour le moment. Ajoutez votre premier prospect.',
    'linked_account' => 'Compte',
    'do_not_contact' => 'Ne pas contacter',
    'follow_member' => 'Ajouter au suivi',
    'field' => [
        'first_name' => 'Prénom',
        'last_name' => 'Nom',
        'email' => 'Email',
        'phone' => 'Téléphone',
        'company' => 'Société',
        'email_or_phone_hint' => 'Un email ou un téléphone suffit.',
    ],
    'filter' => [
        'search' => 'Rechercher par nom, email, téléphone, société…',
        'all_statuses' => 'Tous les statuts',
        'idle' => 'Sans contact depuis :days jours',
    ],
    'column' => [
        'name' => 'Nom',
        'email' => 'Email',
        'phone' => 'Téléphone',
        'status' => 'Statut',
        'last_interaction' => 'Dernier contact',
        'source' => 'Provenance',
        'actions' => 'Actions',
    ],
    'source' => [
        'manual' => 'Manuel',
        'signup' => 'Inscription',
        'workshop' => 'Atelier',
        'shell_welcome' => 'Shell',
        'import' => 'Import',
    ],
    'channel' => [
        'none' => 'Mémo interne',
        'call' => 'Appel',
        'meeting' => 'Rendez-vous',
        'email' => 'Email',
        'whatsapp' => 'WhatsApp',
        'sms' => 'SMS',
        'other' => 'Autre contact',
    ],
    'flash' => [
        'contact_created' => 'Contact ajouté.',
        'contact_found' => 'Ce contact existait déjà : il a été retrouvé, rien n\'a été dupliqué.',
        'status_changed' => 'Statut mis à jour : :status.',
        'note_added' => 'Note ajoutée.',
        'member_followed' => 'Le membre est maintenant suivi dans Relations.',
        'member_already_followed' => 'Ce membre était déjà suivi.',
        'follow_conflict' => 'Impossible d\'ajouter ce membre au suivi : un contact portant cet email est déjà relié à un autre compte.',
    ],
];
