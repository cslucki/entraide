<?php

return [

    /*
     * Card keys the workspace can actually render.
     *
     * This mirrors the branch chain in loops/show.blade.php and the
     * RENDERED_CARDS constant introduced by TASK-1081. A card in the catalogue
     * but absent here is declared and not yet built: it must never be offered
     * for activation, because switching it on would add a button that opens on
     * nothing.
     */
    'rendered' => [
        'core.ai_summary',
        'core.manifesto',
        'core.roadmap',
        'core.members',
    ],

    'cards' => [
        'core.ai_summary' => [
            'key' => 'core.ai_summary',
            'label_key' => 'loops.cards.ai_summary.label',
            'description_key' => 'loops.cards.ai_summary.description',
            'empty_title_key' => 'loops.cards.ai_summary.empty_title',
            'empty_body_key' => 'loops.cards.ai_summary.empty_body',
            'action_key' => 'loops.cards.ai_summary.action',
            'icon' => 'sparkles',
            'component' => 'core.ai_summary',
            'order' => 10,
            'permission' => 'loop.active_member',
            'mobile' => 'drawer',
            'default_enabled' => true,
        ],
        'core.manifesto' => [
            'key' => 'core.manifesto',
            'label_key' => 'loops.cards.manifesto.label',
            'description_key' => 'loops.cards.manifesto.description',
            'empty_title_key' => 'loops.cards.manifesto.empty_title',
            'empty_body_key' => 'loops.cards.manifesto.empty_body',
            'action_key' => 'loops.cards.manifesto.action',
            'icon' => 'document',
            'component' => 'core.manifesto',
            'order' => 20,
            'permission' => 'loop.active_member',
            'mobile' => 'drawer',
            'default_enabled' => true,
        ],
        'core.roadmap' => [
            'key' => 'core.roadmap',
            'label_key' => 'loops.cards.roadmap.label',
            'description_key' => 'loops.cards.roadmap.description',
            'empty_title_key' => 'loops.cards.roadmap.empty_title',
            'empty_body_key' => 'loops.cards.roadmap.empty_body',
            'action_key' => 'loops.cards.roadmap.action',
            'icon' => 'map',
            'component' => 'core.roadmap',
            'order' => 30,
            'permission' => 'loop.active_member',
            'mobile' => 'drawer',
            'default_enabled' => true,
        ],
        'core.members' => [
            'key' => 'core.members',
            'label_key' => 'loops.cards.members.label',
            'description_key' => 'loops.cards.members.description',
            'empty_title_key' => 'loops.cards.members.empty_title',
            'empty_body_key' => 'loops.cards.members.empty_body',
            'action_key' => 'loops.cards.members.action',
            'icon' => 'users',
            'component' => 'core.members',
            'order' => 40,
            'permission' => 'loop.active_member',
            'mobile' => 'drawer',
            'default_enabled' => true,
        ],
    ],
];
