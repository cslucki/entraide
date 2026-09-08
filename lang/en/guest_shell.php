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
        // TASK-1461 — Growth V3 §17: what is really possible here, now.
        'workshops_runtime' => 'Workshops open right now',
        'workshops_intro' => 'Published workshops with an upcoming session (only offer these, with their link):',
        'workshop_line' => '- :title:promise — :when — :format — :url',
        'platform_constitution' => 'Platform AI Constitution (Mycelium)',
        'organization_constitution' => ':name AI Constitution (published)',
        'name' => 'Name',
        'tagline' => 'Tagline',
        'headline' => 'Headline',
        'pitch' => 'Promise',
        'description' => 'About',
    ],
    // TASK-1440 — Guest PageContext V1: the "where am I?" block.
    // TASK-1442 — SW-8a: the public overlay (visitor-facing texts).
    'ui' => [
        'open' => 'Any question?',
        'title' => ':name assistant',
        'close' => 'Close',
        'welcome' => 'Hello! I am the public assistant of :name. Ask your question: I answer from public information, no account needed.',
        'placeholder' => 'Your question…',
        'send' => 'Send',
        'sending' => 'Answering…',
        'privacy' => 'Public exchange without an account. Do not share personal data.',
        // TASK-1467 (CDC 21h-23h §2.5) — see the French file for the reasoning:
        // a degraded state always has a technical cause, so an account is never
        // the remedy.
        'degraded_text' => 'The :name assistant is temporarily unavailable. You can keep exploring :name and its workshops.',
        'network_error' => 'The connection failed. Try again in a moment.',
        'failed' => 'The answer could not be produced. You can try again.',
        'refused_generic' => 'Your message could not be handled right now.',
        'refused_max_messages_reached' => 'This conversation reached its message limit. Create an account to continue.',
        'refused_visitor_monthly_quota_reached' => 'You reached the number of messages possible this month. Create an account to continue.',
        'refused_rate_limited' => 'One moment: too many messages in a short time.',
        'refused_input_out_of_bounds' => 'Your message is empty or too long.',
        'remaining' => ':count message(s) left',
        'limit_reached' => 'Conversation limit reached.',
        'first_after' => 'See the :name page ↓',
    ],
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
