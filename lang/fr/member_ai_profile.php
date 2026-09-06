<?php

return [
    'tones' => [
        'sobre' => 'Sobre et professionnel',
        'chaleureux' => 'Chaleureux',
        'direct' => 'Direct',
        'pedagogique' => 'Pédagogique',
        'creatif' => 'Créatif',
        'tres_court' => 'Très court',
    ],

    'target_audience_options' => [
        'entrepreneurs' => 'Entrepreneurs',
        'independants' => 'Indépendants',
        'demandeurs_emploi' => 'Demandeurs d\'emploi',
        'associations' => 'Associations',
        'tpe_pme' => 'TPE/PME',
        'porteurs_projet' => 'Porteurs de projet',
        'reconversion' => 'Reconversion professionnelle',
        'autre' => 'Autre',
    ],

    'help_type_options' => [
        'avis_rapide' => 'Avis rapide',
        'repondre_question' => 'Répondre à une question',
        'relire_document' => 'Relire un document',
        'partager_methode' => 'Partager une méthode',
        'expliquer_outil' => 'Expliquer un outil',
        'mise_en_relation' => 'Mise en relation',
        'prestation' => 'Prestation',
        'mini_atelier' => 'Mini-atelier',
        'accompagnement_duree' => 'Accompagnement dans la durée',
    ],

    'contact_options' => [
        'poser_question_loop' => 'Poser une question dans la boucle',
        'envoyer_demande_echange' => 'Envoyer une demande d\'échange',
        'proposer_rendez_vous' => 'Proposer un rendez-vous',
        'demander_contexte' => 'Demander plus de contexte',
        'consulter_fiche' => 'Consulter ma fiche détaillée',
        'envoyer_message' => 'Envoyer un message',
        'rien_proposer' => 'Ne rien proposer pour l\'instant',
    ],

    'field_skills' => 'Compétences',
    'field_experience' => 'Expérience',
    'field_help_types' => 'Types d\'aide proposés',
    // TASK-1366 — le retrait volontaire. Ce sont les SEULES chaines de cette
    // surface qui passent par __() : le reste de la vue est en francais en dur
    // depuis son origine, et le traduire n'appartient pas a cette TASK
    // (dette consignee). On ne livre pas pour autant un nouveau controle
    // monolingue trois heures apres avoir rendu le Shell bilingue.
    'withdraw_button' => 'Retirer mon profil',
    'withdraw_confirm' => 'Retirer votre profil ? Il ne sera plus propose aux autres membres. Vous pourrez le republier quand vous voudrez, sans rien ressaisir.',
    'withdraw_done' => 'Profil retiré. Il n\'est plus proposé aux autres membres.',
    'republish_button' => 'Republier mon profil',
    'publish_refused_admin_disabled' => 'Ce profil a été désactivé par un administrateur : lui seul peut le republier.',
    'field_service_scope' => 'Cadre d\'intervention',
    'field_boundaries' => 'Limites',
    'field_preferred_contact' => 'Contact préféré',
    'field_tone' => 'Ton du profil',
    'field_target_audience' => 'Public cible',
    'field_problems_helped' => 'Problèmes résolus',
    'field_summary' => 'Résumé du profil',

    'boundary_options' => [
        'pas_urgence' => 'Pas d\'urgence',
        'pas_travail_gratuit' => 'Pas de travail gratuit',
        'pas_conseil_juridique' => 'Pas de conseil juridique',
        'pas_conseil_medical' => 'Pas de conseil médical',
        'pas_hors_domaine' => 'Pas hors de mon domaine',
        'pas_promesse_resultat' => 'Pas de promesse de résultat',
        'pas_disponibilite_permanente' => 'Pas de disponibilité permanente',
    ],
];
