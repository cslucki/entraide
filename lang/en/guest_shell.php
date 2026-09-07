<?php

// TASK-1435 — SW-5 : libelles du contexte public du Shell Welcome.
return [
    // TASK-1437 — SW-7 : repli local (jamais un second appel) et instruction de langue.
    'fallback_unavailable' => 'The answer is temporarily unavailable. You can try again in a moment.',
    'fallback_empty' => 'I have no answer to offer right now. Could you rephrase?',
    'instruction_locale' => 'Answer in this language: :locale.',
    'context' => [
        'identity' => ':name — public presentation',
        'usage_reference' => 'What this space is for — :title',
        'page' => 'Where the visitor is',
        'platform_constitution' => 'Platform AI Constitution (Mycelium)',
        'organization_constitution' => ':name AI Constitution (published)',
        'name' => 'Name',
        'tagline' => 'Tagline',
        'headline' => 'Headline',
        'pitch' => 'Promise',
        'description' => 'About',
    ],
    // TASK-1440 — Guest PageContext V1: the "where am I?" block.
    'page' => [
        'kind' => 'Surface',
        'label' => 'Page',
        'route' => 'Route',
        'public_id' => 'Public identifier',
        'next_step' => 'Possible next step',
        'kind_organization_home' => 'Public home of the organization',
        'kind_signup' => 'Sign-up page of the organization',
        'kind_workshop_page' => 'Workshop page',
        'kind_workshop_session' => 'Workshop session',
        'cta_signup' => 'Create an account',
        'signup_label' => 'Sign-up to :name',
    ],
];
