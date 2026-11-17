<?php

// TASK-1843 — drapeau du pilote Reverb sur le badge de notifications
// internes (`MemberNotification`). FAUX par defaut, INDEPENDANT de
// `loop_chat.realtime_enabled` (TASK-1839/1841) : les deux surfaces
// partagent la MEME connexion `window.Echo` (TASK-1841/1842 — un seul
// client Reverb par navigateur) mais s'activent chacune separement. Sans
// effet sur `NotificationCenterController`/le rechargement de page, qui
// reste le chemin de secours existant (TASK-1373).
return [
    'enabled' => (bool) env('NOTIFICATIONS_REALTIME_ENABLED', false),
];
