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
        'platform_constitution' => 'Platform AI Constitution (Mycelium)',
        'organization_constitution' => ':name AI Constitution (published)',
        'name' => 'Name',
        'tagline' => 'Tagline',
        'headline' => 'Headline',
        'pitch' => 'Promise',
        'description' => 'About',
    ],
];
