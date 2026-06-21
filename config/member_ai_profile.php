<?php

return [
    'statuses' => [
        'draft',
        'ready_for_generation',
        'generated',
        'pending_validation',
        'published',
        'disabled',
    ],

    'tones' => [
        'sobre' => 'Sobre et professionnel',
        'chaleureux' => 'Chaleureux',
        'direct' => 'Direct',
        'pedagogique' => 'Pédagogique',
        'creatif' => 'Créatif',
        'tres_court' => 'Très court',
    ],

    'target_audience_options' => [
        'entrepreneurs',
        'independants',
        'demandeurs_emploi',
        'associations',
        'tpe_pme',
        'porteurs_projet',
        'reconversion',
        'autre',
    ],

    'help_type_options' => [
        'avis_rapide',
        'repondre_question',
        'relire_document',
        'partager_methode',
        'expliquer_outil',
        'mise_en_relation',
        'prestation',
        'mini_atelier',
        'accompagnement_duree',
    ],

    'contact_options' => [
        'poser_question_loop',
        'envoyer_demande_echange',
        'proposer_rendez_vous',
        'demander_contexte',
        'consulter_fiche',
        'envoyer_message',
        'rien_proposer',
    ],

    'boundary_options' => [
        'pas_urgence',
        'pas_travail_gratuit',
        'pas_conseil_juridique',
        'pas_conseil_medical',
        'pas_hors_domaine',
        'pas_promesse_resultat',
        'pas_disponibilite_permanente',
    ],

    'validation_rules' => [
        'member_profile_summary' => 'nullable|string|max:500',
        'service_scope' => 'nullable|string|max:500',
        'experience_context' => 'nullable|string|max:1000',
        'preferred_contact_action' => 'nullable|string|max:50',
        'tone' => 'nullable|string|max:30',
        'target_audience' => 'nullable|array',
        'problems_helped' => 'nullable|array',
        'skills' => 'nullable|array|max:10',
        'help_types' => 'nullable|array',
        'boundaries' => 'nullable|array',
        'good_request_examples' => 'nullable|array|max:3',
        'bad_request_examples' => 'nullable|array|max:3',
    ],
];
