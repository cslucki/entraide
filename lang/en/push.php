<?php

// TASK-1739 — Web Push subscriptions, one per device.

return [
    'device_title' => 'Notifications on this device',
    'device_intro' => 'Get notified when someone needs you, even when BouclePro is closed.',

    // The four real states. None is a guess: three are read from the
    // browser, the last one comes from the server.
    'state_on' => 'Enabled on this device',
    'state_off' => 'Not enabled on this device',
    'state_denied' => 'Blocked by your browser',
    'state_unsupported' => 'Not supported by this browser',
    'state_unavailable' => 'Unavailable for now',
    'state_checking' => 'Checking…',

    'enable' => 'Enable notifications',
    'disable' => 'Disable on this device',

    'denied_help' => 'Your browser blocked notifications for BouclePro. To turn them back on, open the site settings in your browser and allow notifications, then come back to this page.',
    'unsupported_help' => 'This browser does not offer web notifications. On iPhone, first install BouclePro on your home screen: notifications are only available from the installed app.',
    'unavailable_help' => 'Notifications are not offered on this space yet. Your browser does support them: there is nothing for you to do.',

    'devices_count' => '{0}No device registered|{1}1 device registered|[2,*]:count devices registered',

    // We do not promise what has not shipped (CDC 3.2).
    // TASK-1744 — `not_yet_sending` is DEAD: true under T1739 (no channel in
    // the catalogue), the exact opposite of the truth once T1744 opened it.
    // It told whoever had just enabled their device that they would receive
    // nothing, right above the very boxes they had to tick.
    'ready_none' => 'Your device is ready, but no notification is enabled yet: tick “On my devices” below, then save.',
    'ready_some' => 'Your device is ready. You will receive the notifications ticked “On my devices” below.',
    'ready_none_action' => 'Go to per-event settings',

    'enable_failed' => 'Enabling did not go through. Try again in a moment.',
    'disable_failed' => 'Disabling did not go through. Try again in a moment.',
    'disabled' => 'Push notifications are not available at the moment.',
    'key_stale' => 'This device was subscribed with a security key that is no longer in use: it can no longer receive notifications. Click “Enable” to subscribe it again.',

    // TASK-1821 — LOCAL recipe only: identify this browser's own
    // subscription without guessing — label and fingerprint already in
    // the database, no new collection. `%label%` and `%fingerprint%` are
    // substituted in JavaScript, never by the server (see push-device.js).
    'device_registered' => 'Registered device: %label% · #%fingerprint%',
    'device_registered_unlabeled' => 'Registered device: #%fingerprint%',
];
