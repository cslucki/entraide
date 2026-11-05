<?php

return [

    /*
    | TASK-1757 (§9.7) — see the French file for why this file exists: the badge
    | catalogue is DATA, seeded by `BadgeSeeder`, so TASK A's lexicon pass never
    | reached it. Rendering goes through a key indexed on `badge->key`, falling
    | back to the column.
    */

    'keys' => [
        'first_exchange' => 'First help exchange',
        'five_exchanges' => 'Regular helper',
        'ten_exchanges' => 'Community pillar',
        'first_service' => 'First listing',
        'five_services' => 'Five listings published',
        'top_rated' => 'Highly appreciated',
        'generous' => 'Says thank you',

        'first_help' => 'First helping hand',
        'five_people_helped' => '5 people helped',
        'helped_newcomer' => 'Helped a newcomer',
    ],

    'descriptions' => [
        'first_exchange' => 'Took part in their first help exchange.',
        'five_exchanges' => 'Took part in 5 help exchanges.',
        'ten_exchanges' => 'Took part in 10 help exchanges.',
        'first_service' => 'Published their first listing.',
        'five_services' => 'Published 5 listings or more.',
        'top_rated' => 'Has an average rating of 4.5 or above (minimum 3 reviews).',
        'generous' => 'Thanked 3 people who had helped them.',

        'first_help' => 'Gave their first helping hand.',
        'five_people_helped' => 'Helped 5 different people.',
        'helped_newcomer' => 'Helped someone with their very first exchange.',
    ],

];
