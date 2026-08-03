<?php

/*
|--------------------------------------------------------------------------
| Loop types
|--------------------------------------------------------------------------
|
| A Loop type is not a label: it defines the initial card composition of the
| Loop. Everything a type is lives in this file, so adding a fifth one later
| means one entry here — never new conditionals scattered through controllers
| and views.
|
| `cards` lists keys of config/loop_cards.php. Only cards that actually exist
| there are applied; anything else is ignored rather than faked, so this file
| can name a card ahead of its implementation without breaking a preset.
|
| Applying a preset is additive and idempotent: missing cards are added, and
| nothing is ever removed. A type is a starting point, not a cage.
|
*/

return [

    'default' => 'general',

    /*
     * Historical value predating typed Loops. Every existing Loop carries it.
     * Read as `general`, never offered in a form, never written to new rows.
     */
    'legacy_aliases' => [
        'custom' => 'general',
        'system' => 'general',
    ],

    'types' => [

        'general' => [
            'key' => 'general',
            'label_key' => 'loops.types.general.label',
            'description_key' => 'loops.types.general.description',
            'icon' => 'sparkles',
            'order' => 10,
            'cards' => [
                'core.ai_summary',
                'core.manifesto',
                'core.members',
                'core.roadmap',
            ],
        ],

        'project' => [
            'key' => 'project',
            'label_key' => 'loops.types.project.label',
            'description_key' => 'loops.types.project.description',
            'icon' => 'map',
            'order' => 20,
            'cards' => [
                'core.ai_summary',
                'core.manifesto',
                'core.members',
                'core.roadmap',
            ],
        ],

        'training' => [
            'key' => 'training',
            'label_key' => 'loops.types.training.label',
            'description_key' => 'loops.types.training.description',
            'icon' => 'academic',
            'order' => 30,
            /*
             * Pedagogical activity cards do not exist yet. Listing them here
             * would be pretending: the preset stays on what is really shipped,
             * and gains them the day they land in config/loop_cards.php.
             */
            'cards' => [
                'core.manifesto',
                'core.members',
                'core.roadmap',
            ],
        ],

        'peer_support' => [
            'key' => 'peer_support',
            'label_key' => 'loops.types.peer_support.label',
            'description_key' => 'loops.types.peer_support.description',
            'icon' => 'heart',
            'order' => 40,
            /*
             * Help requests and resources are their own product decision
             * (TASK D of the TASK-1072 spec, still unwritten). Same rule:
             * only real cards here.
             */
            'cards' => [
                'core.manifesto',
                'core.members',
            ],
        ],

    ],
];
