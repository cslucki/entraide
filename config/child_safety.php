<?php

/*
| TASK-1905 — signalements « Securite des enfants ».
|
| `alert_email` : contact designe qui recoit l'alerte a chaque nouveau
| signalement `child_safety` (pose dans Laravel Cloud). Vide = AUCUN envoi,
| alerte technique explicite (Log::critical + historique + bandeau superadmin).
|
| `restricted_admin_email_suffixes` : hors environnement local/test, un
| superadmin dont l'adresse finit ainsi (comptes de demonstration, QA) ne lit
| ni ne traite aucun signalement `child_safety`.
*/
return [
    'alert_email' => env('CHILD_SAFETY_ALERT_EMAIL'),

    'alert_locale' => env('CHILD_SAFETY_ALERT_LOCALE', 'fr'),

    'restricted_admin_email_suffixes' => ['.test', '.invalid', '.example', '.local', '.localhost'],
];
