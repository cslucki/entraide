<?php

return [
    'title' => 'Member directory',
    'member_count' => ':count registered member|:count registered members',
    'active_services' => 'Active :label',
    'open_requests' => 'Open requests',
    'request_count' => ':count request|:count requests',
    'setup_title' => 'Database setup required',
    'setup_body' => 'The member directory is not available yet because the local database does not contain a configured organization.',
    'setup_intro' => 'To initialize the local environment:',
    'setup_steps' => [
        'Synchronize the production database via',
        'Run local migrations:',
        'Inject QA accounts:',
        'Clear the cache:',
    ],
];
