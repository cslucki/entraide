<?php

/**
 * Terminologie de la plateforme.
 *
 * Modifier ici pour changer les libellés dans tout le site.
 * Les organisations pourront surcharger ces valeurs via organization->terms (JSON).
 * Dans les vues, accéder via la variable $T partagée par AppServiceProvider.
 *
 * Exemple : {{ $T['Services'] }} → "Micro-services"
 */

return [
    'service' => 'micro-service',
    'services' => 'micro-services',
    'Service' => 'Micro-service',
    'Services' => 'Micro-services',

    'request' => 'demande d\'aide',
    'requests' => 'demandes d\'aide',
    'Request' => 'Demande d\'aide',
    'Requests' => 'Demandes d\'aide',
];
