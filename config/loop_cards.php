<?php

return [

    /*
     * Le nombre de Cards que la zone principale accepte.
     *
     * Trois, parce que c'est ce qui permet de comprendre en ouvrant une Boucle
     * ce qu'on y fait. Au-dela, la barre cesse d'annoncer une intention et
     * devient une boite a outils.
     *
     * Le cadre permanent — ChatLoop, Manifeste, Membres — ne compte pas : il est
     * partout, donc il ne distingue rien.
     */
    'grid_slots' => 3,

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
     *   placement        ou la Card vit a l'ecran (TASK-1090) :
     *                      `grid`         la zone des outils, trois au maximum
     *                      `frame`        le cadre permanent, hors grille
     *                      `chat_action`  une action IA de ChatLoop
     *   category         a quoi elle sert, pour regrouper le catalogue
     *   scope            `universal` (toute Boucle) ou `contextual` (un metier)
     *   requires         Cards dont celle-ci a besoin pour avoir un sens
     *   incompatible_with Cards avec lesquelles elle ne peut pas coexister
     *   replaceable      peut-elle etre echangee dans un emplacement distinctif
     *
     * `placement` ne retire rien : une Card `frame` reste declaree, gardee par
     * ses permissions et comptee par l'administration. Elle quitte seulement la
     * grille — une barre de six outils ne dit plus ce qu'on fait dans la Boucle,
     * elle donne une boite a outils au lieu d'une intention.
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
            // Le resume est une action de la conversation, pas un outil au meme
            // titre que la Roadmap : il rejoint les actions IA de ChatLoop.
            'placement' => 'chat_action',
            'category' => 'ai',
            'scope' => 'universal',
            'requires' => [],
            'incompatible_with' => [],
            'replaceable' => false,
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
            // Cadre permanent : le texte de reference se consulte, il ne
            // s'« ouvre » pas comme un instrument de travail.
            'placement' => 'frame',
            'category' => 'foundation',
            'scope' => 'universal',
            'requires' => [],
            'incompatible_with' => [],
            'replaceable' => false,
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
            'placement' => 'grid',
            'category' => 'action',
            'scope' => 'universal',
            'requires' => [],
            'incompatible_with' => [],
            'replaceable' => true,
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
            'placement' => 'grid',
            'category' => 'decision',
            'scope' => 'universal',
            'requires' => [],
            'incompatible_with' => [],
            'replaceable' => true,
            'permission' => 'loop.active_member',
            'mobile' => 'drawer',
            'default_enabled' => true,
        ],
        'core.events' => [
            'key' => 'core.events',
            'label_key' => 'loops.cards.events.label',
            'description_key' => 'loops.cards.events.description',
            'empty_title_key' => 'loops.cards.events.empty_title',
            'empty_body_key' => 'loops.cards.events.empty_body',
            'action_key' => 'loops.cards.events.action',
            'icon' => 'calendar',
            'component' => 'loop-events-card',
            'view' => null,
            'view_permission' => 'events.view',
            'required' => false,
            'order' => 37,
            'placement' => 'grid',
            'category' => 'rhythm',
            'scope' => 'universal',
            'requires' => [],
            'incompatible_with' => [],
            'replaceable' => true,
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
            // Cadre permanent : savoir qui est la est un contexte, pas une
            // activite.
            'placement' => 'frame',
            'category' => 'foundation',
            'scope' => 'universal',
            'requires' => [],
            'incompatible_with' => [],
            'replaceable' => false,
            'permission' => 'loop.active_member',
            'mobile' => 'drawer',
            'default_enabled' => true,
        ],
    ],
];
