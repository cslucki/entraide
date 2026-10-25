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
    'search_placeholder' => 'Search by first or last name...',
    'search_label' => 'Search for a member',
    'search_submit' => 'Search',
    'search_clear' => 'Clear',
    'search_no_result' => 'No member matches this search.',
    'website_title' => 'Website',
    'linkedin_title' => 'LinkedIn',
];
