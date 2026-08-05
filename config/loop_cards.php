<?php

return [

    /*
     * Le catalogue des Cards — la seule declaration.
     *
     * Une Card etait auparavant declaree a trois endroits que rien ne tenait
     * d'accord : la cle `rendered` de ce fichier, la constante
     * LoopController::RENDERED_CARDS, et une chaine de conditions sur la cle
     * dans loops/show.blade.php. Les trois disaient la meme chose ; rien ne les
     * y obligeait. Une cinquieme Card ajoutee a deux endroits sur trois passait
     * la revue et ouvrait sur un panneau vide en recette.
     *
     * Tout se lit desormais ici, a travers LoopCardRegistry.
     *
     * Champs :
     *
     *   component        composant Livewire qui rend la Card, ou null
     *   view             vue Blade qui la rend, quand elle n'a pas de composant
     *   view_permission  permission qui protege son *contenu*, distincte du fait
     *                    de posseder la Card ; null = tout membre actif
     *   required         une Card requise ne se desactive jamais
     *   order            ordre d'affichage dans le workspace
     *
     * Une Card sans `component` ni `view` est declaree mais pas encore
     * construite : elle n'est jamais proposee a l'activation et ne rend rien.
     * C'est ce qui remplace la liste `rendered` tenue a la main.
     *
     * `component` et `view` sont ecrits ici par des developpeurs et verifies
     * contre ce catalogue avant usage : aucune chaine soumise par un
     * utilisateur ne peut designer un composant.
     */
    'cards' => [
        'core.ai_summary' => [
            'key' => 'core.ai_summary',
            'label_key' => 'loops.cards.ai_summary.label',
            'description_key' => 'loops.cards.ai_summary.description',
            'empty_title_key' => 'loops.cards.ai_summary.empty_title',
            'empty_body_key' => 'loops.cards.ai_summary.empty_body',
            'action_key' => 'loops.cards.ai_summary.action',
            'icon' => 'sparkles',
            'component' => 'loop-ai-summary-card',
            'view' => null,
            'view_permission' => null,
            'required' => false,
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
            'component' => 'loop-manifesto-card',
            'view' => null,
            'view_permission' => 'manifesto.view',
            'required' => false,
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
            'component' => 'loop-roadmap-card',
            'view' => null,
            'view_permission' => 'roadmap.view',
            'required' => false,
            'order' => 30,
            'permission' => 'loop.active_member',
            'mobile' => 'drawer',
            'default_enabled' => true,
        ],
        'core.polls' => [
            'key' => 'core.polls',
            'label_key' => 'loops.cards.polls.label',
            'description_key' => 'loops.cards.polls.description',
            'empty_title_key' => 'loops.cards.polls.empty_title',
            'empty_body_key' => 'loops.cards.polls.empty_body',
            'action_key' => 'loops.cards.polls.action',
            'icon' => 'chart',
            'component' => 'loop-polls-card',
            'view' => null,
            'view_permission' => 'polls.view',
            'required' => false,
            'order' => 35,
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
            // Un partiel Blade et non un composant Livewire : cette Card lit des
            // donnees deja preparees par le controleur de la page.
            'component' => null,
            'view' => 'loops.cards.members',
            'view_permission' => 'loop_members.view',
            // Sans les membres, un workspace n'a plus de sens.
            'required' => true,
            'order' => 40,
            'permission' => 'loop.active_member',
            'mobile' => 'drawer',
            'default_enabled' => true,
        ],
    ],
];
