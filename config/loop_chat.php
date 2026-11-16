<?php

// TASK-1839 — drapeau du pilote Reverb sur LoopChat. FAUX par defaut :
// l'ecouteur Echo ne s'enregistre que si ce drapeau est pose explicitement
// (meme idiome que TURNSTILE_LOGIN_ENABLED, WEB_PUSH_ENABLED). Sans effet
// sur `wire:poll.3s`, qui reste le chemin par defaut.
return [
    'realtime_enabled' => (bool) env('LOOP_CHAT_REALTIME_ENABLED', false),
];
