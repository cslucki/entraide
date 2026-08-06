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
| `available` says whether a type may be *chosen* — in a creation form, or when
| reassigning a Loop. An unavailable type is not deleted and not hidden: it
| stays visible in the admin, it keeps whatever Loops already carry it, and it
| simply cannot be picked. That is how a type under construction waits for its
| cards without pretending to be finished.
|
| These are the DEFAULTS. The super-admin may change presets and availability
| from /admin/loop-types without a deployment; overrides live in
| loop_type_settings and are read through LoopTypeSettingsService.
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
     *
     * `system` was listed here in CP4 on speculation and has been removed: it
     * appears on no Loop, in no factory and in no seeder — the `'type' =>
     * 'system'` occurrences in the codebase all belong to `messages` and
     * `loop_messages`. Its only mention as a Loop type is in the TASK-1072
     * spec, as a value to *reject*. An unknown value already resolves to the
     * default, so behaviour is unchanged. No speculative aliases.
     */
    'legacy_aliases' => [
        'custom' => 'general',
    ],

    'types' => [

        'general' => [
            // Le document racine porte un nom different selon le type,
            // mais reste le meme concept. Lu par le registry, jamais
            // par une condition sur $loop->type.
            'root_document_label_key' => 'loops.root_document.general',
            'root_document_sections' => [
                'loops.root_document_sections.why',
                'loops.root_document_sections.how',
                'loops.root_document_sections.principles',
            ],
            'key' => 'general',
            'label_key' => 'loops.types.general.label',
            'description_key' => 'loops.types.general.description',
            'icon' => 'sparkles',
            'order' => 10,
            'available' => true,
            /*
             * Communaute — cle `general`, libelle change en TASK-1090.
             *
             * Le cadre permanent (Manifeste, Membres) plus les trois Cards
             * distinctives de la matrice canonique : Evenements, Sondage,
             * Dossiers. La cible est atteinte depuis TASK-1091, qui a construit
             * la Card Dossiers laissee manquante par TASK-1090.
             *
             * La Roadmap n'est plus au socle : une Boucle de vie collective n'a
             * pas de feuille de route par defaut. Elle reste activable
             * localement, et les Boucles qui l'ont la gardent — la
             * synchronisation des presets n'a jamais rien retire.
             */
            'cards' => [
                'core.manifesto',
                'core.members',
                'core.polls',
                'core.events',
                'core.dossiers',
            ],
        ],

        'project' => [
            // Le document racine porte un nom different selon le type,
            // mais reste le meme concept. Lu par le registry, jamais
            // par une condition sur $loop->type.
            'root_document_label_key' => 'loops.root_document.project',
            'root_document_sections' => [
                'loops.root_document_sections.why',
                'loops.root_document_sections.goals',
                'loops.root_document_sections.audience',
                'loops.root_document_sections.how',
                'loops.root_document_sections.principles',
                'loops.root_document_sections.next',
            ],
            'key' => 'project',
            'label_key' => 'loops.types.project.label',
            'description_key' => 'loops.types.project.description',
            'icon' => 'map',
            'order' => 20,
            'available' => true,
            'cards' => [
                'core.ai_summary',
                'core.manifesto',
                'core.roadmap',
                'core.members',
            ],
        ],

        /*
         * Coaching sits between the two: an accompaniment with objectives and a
         * follow-up, not a project to deliver and not a plain conversation.
         *
         * Its preset is a starting point, not a decision written in stone —
         * it is composed from /admin/loop-types without a deployment, which is
         * exactly what that screen exists for.
         */
        'coaching' => [
            // Le document racine porte un nom different selon le type,
            // mais reste le meme concept. Lu par le registry, jamais
            // par une condition sur $loop->type.
            'root_document_label_key' => 'loops.root_document.coaching',
            'root_document_sections' => [
                'loops.root_document_sections.why',
                'loops.root_document_sections.goals',
                'loops.root_document_sections.how',
            ],
            'key' => 'coaching',
            'label_key' => 'loops.types.coaching.label',
            'description_key' => 'loops.types.coaching.description',
            'icon' => 'compass',
            'order' => 25,
            'available' => true,
            'cards' => [
                'core.ai_summary',
                'core.roadmap',
                'core.members',
            ],
        ],

        'training' => [
            // Le document racine porte un nom different selon le type,
            // mais reste le meme concept. Lu par le registry, jamais
            // par une condition sur $loop->type.
            'root_document_label_key' => 'loops.root_document.training',
            'root_document_sections' => [
                'loops.root_document_sections.why',
                'loops.root_document_sections.goals',
                'loops.root_document_sections.how',
                'loops.root_document_sections.next',
            ],
            'key' => 'training',
            'label_key' => 'loops.types.training.label',
            'description_key' => 'loops.types.training.description',
            'icon' => 'academic',
            'order' => 30,
            /*
             * Not offered anywhere: its pedagogical cards do not exist yet, and
             * a type that ships nothing of its own is a promise, not a product.
             */
            'available' => false,
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
            // Le document racine porte un nom different selon le type,
            // mais reste le meme concept. Lu par le registry, jamais
            // par une condition sur $loop->type.
            'root_document_label_key' => 'loops.root_document.peer_support',
            'root_document_sections' => [
                'loops.root_document_sections.why',
                'loops.root_document_sections.principles',
                'loops.root_document_sections.how',
            ],
            'key' => 'peer_support',
            'label_key' => 'loops.types.peer_support.label',
            'description_key' => 'loops.types.peer_support.description',
            'icon' => 'heart',
            'order' => 40,
            'available' => false,
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
