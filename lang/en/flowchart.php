<?php

/**
 * TASK-1608 — labels for the interactive map.
 *
 * Loop NAMES and DESCRIPTIONS are not here: they come from what members wrote,
 * and are never machine-translated (§13 of the mandate).
 */
return [
    'title' => 'Flowchart',
    'subtitle' => 'How BouclePro works, and what actually exists in this Organization.',

    // First level (§4)
    'root' => 'What would you like to do?',

    'intent_need_help' => 'I need help',
    'intent_need_help_hint' => 'Say what is blocking you. BouclePro helps you phrase it, then finds who can answer.',
    'intent_offer_help' => 'I can help',
    'intent_offer_help_hint' => 'Describe what you know how to do. Your skills become visible where they are useful.',
    'intent_explore_idea' => 'Explore an idea',
    'intent_explore_idea_hint' => 'A hunch, a fascination, a subject to dig into: this Organization and its documents become your material.',
    'intent_connect' => 'Connect people',
    'intent_connect_hint' => 'These people should meet. Make the introduction rather than waiting for it.',

    // The mechanism (§5)
    'step_clarify' => 'Clarification',
    'step_clarify_hint' => 'A vague intention becomes a clear request. This is the first thing BouclePro does, whichever door you came through.',
    'step_match' => 'Relevant people and Loops',
    'step_match_hint' => 'The request meets those who can answer it, in the Loops where the subject already lives.',
    'step_exchange' => 'Exchanges',
    'step_exchange_hint' => 'The conversation happens in the Loop, and stays there: it becomes the group memory.',
    'step_ai' => 'AI assistance',
    'step_ai_hint' => 'AI helps you phrase, retrieve and summarise. It never decides for you.',
    'step_resources' => 'Resources and cards',
    'step_resources_hint' => 'The Loop toolkit: cards, follow-ups, agenda — whatever the group chose to switch on.',
    'step_dossiers' => 'Dossiers and documents',
    'step_dossiers_hint' => 'Shared documents become a searchable base, bounded by what you are allowed to read.',
    'step_synthesis' => 'Sourced synthesis',
    'step_synthesis_hint' => 'An answer that cites its sources. With no source, BouclePro says so rather than inventing one.',
    'step_decision' => 'Decision',
    'step_decision_hint' => 'The group decides, and the record of that decision stays attached to the Loop.',

    // Where it leads (§5)
    'outcome_entraide' => 'Mutual aid',
    'outcome_relation' => 'Introductions',
    'outcome_learning' => 'Learning',
    'outcome_coordination' => 'Coordination',
    'outcome_action' => 'Action',
    'outcome_memory' => 'Shared memory',

    // The real-Loops branch (§11 — no recommendation engine)
    'explore_loops' => 'Explore the Loops of this Organization',
    // The NODE label: a card has no room for a sentence. The long form stays
    // in the card sections, where it has space to be said.
    'explore_loops_node' => 'Explore the Loops',
    'explore_loops_empty' => 'No Loop to explore here yet.',
    'guest_hint' => 'Sign in to explore the Loops of this Organization.',

    // Access states — the same four states as the catalogue and the Shell
    'access_open' => 'Open to join',
    'access_request' => 'On request',
    'access_pending' => 'Request pending',
    'access_member' => 'You are a member',

    // Actions
    'cta_view' => 'View Loop',
    'cta_open' => 'Open Loop',
    'cta_request' => 'Request to join',
    'back' => 'Back',
    'overview' => 'Overview',
    'reset' => 'Reset',

    // Accessibility / fallback (§15)
    'fallback_title' => 'The path, in text',
    'fallback_intro' => 'The same map, readable without the interactive graph.',
    'fallback_loops' => 'Loops reachable from this page',
    'graph_label' => 'Interactive flowchart of this Organization',

    // TASK-1608 §2 fixes — the FIRST STEP specific to each intention.
    // Different doors, shared engine: without these, all four revealed
    // exactly the same nodes.
    'entry_need_help' => 'Clarify what I need',
    'entry_need_help_hint' => 'Say what is blocking you, in your own words. BouclePro rephrases it with you until it is clear to someone else.',
    'entry_offer_help' => 'Describe what I can bring',
    'entry_offer_help_hint' => 'Name a skill, some time, a craft. What you offer becomes visible where it is useful.',
    'entry_explore_idea' => 'Frame the subject to explore',
    'entry_explore_idea_hint' => 'Ask the question, even roughly. A hunch is something you work on; it need not already be a thesis.',
    'entry_connect' => 'Name the people, and the reason',
    'entry_connect_hint' => 'Say who should meet, and why now. The reason matters as much as the names.',

    // The two foldings used by the overview.
    'aggregate_engine' => 'AI, resources and documents',
    'aggregate_engine_hint' => 'Assistance, the Loop toolkit and shared Dossiers: what BouclePro draws on to answer.',
    'aggregate_decide' => 'Synthesis and decision',
    'aggregate_decide_hint' => 'An answer that cites its sources, then a choice the group owns and that stays on record.',

    // Graph toolbar (§8 fixes).
    'zoom_in' => 'Zoom in',
    'zoom_out' => 'Zoom out',
    'recenter' => 'Recentre on selection',
    'fit' => 'Fit to view',
    'continue' => 'Continue',

    // Card walkthrough (§11 fixes).
    'cards_intents_title' => 'What would you like to do?',
    'cards_engine_title' => 'How BouclePro supports you',
    'cards_outcomes_title' => 'What it produces',
    'cards_loops_title' => 'Loops you can explore',
];
