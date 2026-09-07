<?php

return [
    // TASK-1414 — initial pipeline of an Organization, seeded ONCE in its
    // locale; from then on these are its own labels, renamed freely.
    'default_status' => [
        'new' => 'New',
        'to_contact' => 'To contact',
        'in_progress' => 'In progress',
        'quote_sent' => 'Quote sent',
        'client' => 'Client',
        'lost' => 'Lost',
    ],
];
