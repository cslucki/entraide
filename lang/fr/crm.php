<?php

return [
    // TASK-1414 — pipeline initial d'une Organization, seme UNE fois dans sa
    // locale ; ensuite ce sont ses libelles, qu'elle renomme librement.
    'default_status' => [
        'new' => 'Nouveau',
        'to_contact' => 'À contacter',
        'in_progress' => 'Contact en cours',
        'quote_sent' => 'Devis envoyé',
        'client' => 'Client',
        'lost' => 'Perdu',
    ],
];
