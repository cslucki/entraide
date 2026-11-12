<?php

return [
    'title' => 'Member directory',
    'member_count' => ':count registered member|:count registered members',
    'active_services' => 'Active :label',
    'open_requests' => 'Open requests',
    'request_label' => 'request|requests',
    'setup_title' => 'Database setup required',
    'setup_body' => 'The member directory is not available yet because the local database does not contain a configured organization.',
    'setup_intro' => 'To initialize the local environment:',
    'setup_steps' => [
        'Synchronize the production database via',
        'Run local migrations:',
        'Inject QA accounts:',
        'Clear the cache:',
    ],
    // TASK-1693 bug AA
    'write_to' => 'Write to :name',
    // TASK-1697 correctif 15
    'service_label' => 'proposal|proposals',
    'active_services_title' => 'Active proposals',
    'article_label' => 'article|articles',
    'articles_title' => 'Published articles',
    'loops_title' => 'Loops',
    'search_placeholder' => 'Search a name, a keyword...',

    // TASK-1762 point 10 — the city filter.
    'city_label' => 'Location',
    // TASK-1762 point 12 — « Location », not « All locations » : it is a menu's
    // default option, not a sentence, and it was setting the menu's width.
    'city_all' => 'Location',

    // TASK-1762 point 11.4 — the "New arrivals" view.
    'latest_button' => 'New arrivals',
    // TASK-1812 correctif 04 — the default grid is now sorted alphabetically
    // by first name; this button replaces "New arrivals" once that view is
    // active, to go back.
    'alphabetical_button' => 'Alphabetical order',

    // TASK-1762 point 14 — the fold for more than three Loops.
    'loops_more' => ':count more Loop|:count more Loops',
    'loops_reduce' => 'collapse',
    'joined_ago' => 'Joined :when',

    // TASK-1762 point 11.2 — the block that loads the next page.
    'load_more' => 'See the next member|See the next :count members',

    // TASK-1762 point 9 — used when the Organization has opened the Structure field.
    'search_placeholder_with_structure' => 'Search a name, an organisation, a keyword...',
    'search_label' => 'Search for a member',
    'search_submit' => 'Search',
    'search_clear' => 'Clear',
    'search_no_result' => 'No member matches this search.',
    'website_title' => 'Website',
    'linkedin_title' => 'LinkedIn',
];
