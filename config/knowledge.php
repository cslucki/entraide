<?php

return [

    /*
    |--------------------------------------------------------------------------
    | TASK-1832 — planification de `knowledge:derive-due`
    |--------------------------------------------------------------------------
    |
    | `knowledge:derive-due` dispatche `DeriveLoopConversationKnowledge` sans
    | jamais appeler `onConnection()` : sous `QUEUE_CONNECTION=sync` (PROD
    | aujourd'hui, meme apres l'activation de la Managed Queue `notifications`
    | de T1829/T1830 — ce balayage n'y est pas rattache), chaque job dispatche
    | par ce balayage s'execute EN LIGNE, dans le processus meme du Scheduler.
    |
    | Contrairement aux notifications, ce traitement n'a aucune strategie
    | asynchrone validee. L'activer sans ce garde lancerait au premier passage
    | autant de compilations IA qu'il y a de Boucles eligibles (mesure en PROD
    | le 15/11/2026 : 12, sous le plafond MAX_PAR_BALAYAGE=25 du dispatcher,
    | mais rien n'empeche ce nombre de grandir avant qu'une strategie async
    | soit validee).
    |
    | FAUX par defaut : une variable absente ne doit jamais allumer une
    | planification qui consomme du budget IA. Meme doctrine que
    | `webpush.enabled` (T1739) et `queue.notifications_managed_queue_connection`
    | (T1829).
    |
    | Ce drapeau ne retire RIEN : `php artisan knowledge:derive-due` et
    | `knowledge:derive-loop-conversations` restent executables a la main,
    | quelle que soit sa valeur — seule la ligne PLANIFIEE dans
    | `routes/console.php` en depend.
    */
    'derive_due_scheduled_enabled' => (bool) env('KNOWLEDGE_DERIVE_DUE_SCHEDULED_ENABLED', false),

];
