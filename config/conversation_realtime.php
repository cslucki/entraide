<?php

// TASK-1845 — drapeau du pilote Reverb sur la diffusion des messages de
// conversation (`ConversationMessageCreated`). FAUX par defaut, INDEPENDANT
// de `loop_chat.realtime_enabled` (TASK-1839) et de
// `notifications_realtime.enabled` (TASK-1843) : les trois surfaces
// partagent la MEME connexion `window.Echo` (un seul client Reverb par
// navigateur) mais s'activent chacune separement. Aucun JavaScript cote
// navigateur n'ecoute ce canal dans cette TASK (hors perimetre explicite du
// mandat) — seule la diffusion serveur->Reverb existe pour l'instant.
return [
    'enabled' => (bool) env('CONVERSATION_REALTIME_ENABLED', false),
];
