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
    'state_checking' => 'Checking…',

    'enable' => 'Enable notifications',
    'disable' => 'Disable on this device',

    'denied_help' => 'Your browser blocked notifications for BouclePro. To turn them back on, open the site settings in your browser and allow notifications, then come back to this page.',
    'unsupported_help' => 'This browser does not offer web notifications. On iPhone, first install BouclePro on your home screen: notifications are only available from the installed app.',

    'devices_count' => '{0}No device registered|{1}1 device registered|[2,*]:count devices registered',

    // We do not promise what has not shipped (CDC 3.2).
    'not_yet_sending' => 'Your device is registered. Sending will be switched on soon: you will not receive anything just yet.',

    'enable_failed' => 'Enabling did not go through. Try again in a moment.',
    'disable_failed' => 'Disabling did not go through. Try again in a moment.',
    'disabled' => 'Push notifications are not available at the moment.',
    'key_stale' => 'This device was subscribed with a security key that is no longer in use: it can no longer receive notifications. Click “Enable” to subscribe it again.',
];
