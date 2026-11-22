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
    'ready_none' => 'Your device is ready, but no notification is enabled yet: tick “On my devices” in your per-event settings, then save.',
    'ready_some' => 'Your device is ready. You will receive the notifications ticked “On my devices” in your per-event settings.',
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

    // TASK-1877 — the dedicated page and its REAL Web Push test.
    //
    // MASTER ruling (R2): a provider that accepts has DISPLAYED nothing.
    // None of these texts says "received": the person checks for themself.
    'device_back' => '← Back to notification settings',
    'device_settings_button' => 'Settings on this device',
    'test_button' => 'Send a test notification',
    'test_payload_title' => 'Test notification',
    'test_payload_body' => 'If you can see this message, BouclePro notifications reach this device.',
    'test_sent' => 'Test notification sent. Check that it arrives on this device.',
    'test_failed' => 'Sending failed: the notification service did not accept the message. Try again in a moment; if it persists, disable then re-enable notifications on this device.',
    'test_revoked' => 'Subscription expired. Re-enable notifications.',
    'test_revoked_cleanup_failed' => 'Subscription expired, but your browser could not forget it: this device will not receive notifications. Reload the page, then re-enable notifications.',
    'test_unknown' => 'This device is not recognised as subscribed to your account. Disable then re-enable notifications on this device.',
    'test_throttled' => 'Too many attempts in a row. Wait a minute before trying again.',

    // TASK-1877 — "Advanced diagnostics", published (MASTER option B), folded.
    // `%s` is substituted in JavaScript (see push-device.js).
    'diag' => [
        'title' => 'Advanced diagnostics',
        'permission' => 'Permission',
        'origin' => 'Origin',
        'secure' => 'Secure context',
        'scope' => 'Service Worker scope',
        'version' => 'Active Service Worker',
        'subscription' => 'Subscription',
        'fingerprint' => 'Fingerprint',
        'server' => 'Known to the server',
        'key' => 'Subscription key',
        'test_local' => 'Test local display',
        'simulate' => 'Simulate a notification',
        'api_absent' => 'API missing',
        'yes' => 'yes',
        'no' => 'NO',
        'none' => 'none',
        'no_answer' => '(no answer)',
        'push_supported' => 'push supported',
        'sw_old' => 'OLD — does not answer diagnostics',
        'server_silent' => '(server silent)',
        'key_hidden' => '(not exposed by this browser)',
        'key_match' => 'matches (%s…)',
        'key_diverge' => 'DIVERGES — subscription %s… ≠ server %s…',
        'permission_refused' => 'Permission refused: nothing can be displayed.',
        'local_title' => 'Local display',
        'local_body' => 'If you can see this, the device can display a notification.',
        'local_done' => 'Requested. Visible? Then system display works.',
        'local_failed' => 'Local display failed.',
        'simulate_needs_permission' => 'Permission not granted: start with “Test local display”.',
        'simulate_title' => 'Simulated notification',
        'simulate_body' => 'Handled by the active Service Worker, without network.',
        'simulate_old' => 'The active Service Worker does not know this diagnostic: old copy. Close and reopen the app.',
        'simulate_done' => 'The active Service Worker displayed the notification: the receiving path is intact.',
        'simulate_refused' => 'The Service Worker refused the notification.',
    ],
];
