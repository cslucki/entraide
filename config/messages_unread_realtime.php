<?php

// TASK-1847 — drapeau du pilote Reverb sur le compteur GLOBAL de messages
// non lus (`User::unreadMessagesCount()`). FAUX par defaut, INDEPENDANT
// des trois precedents (`loop_chat.realtime_enabled`, `notifications_
// realtime.enabled`, `conversation_realtime.enabled`) : les quatre
// partagent la MEME connexion `window.Echo` (un seul client Reverb par
// navigateur) mais s'activent chacun separement. A ne JAMAIS confondre
// avec `notifications_realtime.php` : ce drapeau-ci concerne le compteur
// de MESSAGES non lus (`messages.read_at`), un modele de donnees et un
// canal evenementiel totalement distincts de `MemberNotification`.
return [
    'enabled' => (bool) env('MESSAGES_UNREAD_REALTIME_ENABLED', false),
];
