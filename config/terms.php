<?php

/**
 * Terminologie de la plateforme.
 *
 * Modifier ici pour changer les libellés dans tout le site.
 * Les organisations pourront surcharger ces valeurs via organization->terms (JSON).
 * Dans les vues, accéder via la variable $T partagée par AppServiceProvider.
 *
 * Exemple : {{ $T['Services'] }} → "Propositions"
 *
 * TASK-1697 — « micro-service(s) » devient « proposition(s) », decision de
 * Cyril. Les formes `request` ne sont PAS touchees : il n'a pas demande.
 */

return [
    'service' => 'proposition',
    'services' => 'propositions',
    'Service' => 'Proposition',
    'Services' => 'Propositions',

    'request' => 'demande d\'aide',
    'requests' => 'demandes d\'aide',
    'Request' => 'Demande d\'aide',
    'Requests' => 'Demandes d\'aide',
];
