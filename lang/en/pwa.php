<?php

/*
|--------------------------------------------------------------------------
| TASK-1737 — the invitation to install BouclePro
|--------------------------------------------------------------------------
|
| The French file carries the reasoning. In short: the three benefits are TRUE
| TODAY. Sharing and notifications ship in later TASKs and are deliberately
| absent — the CDC (3.2) forbids promising a capability that is not delivered.
|
| "Stays signed in" is absent too: on iOS the Home Screen web app has its own
| storage container, so the Safari session is not inherited.
|
*/

// TASK-1805 — every "BouclePro" below names the APP BEING INSTALLED: it must
// say "BoucleDev" in local (Cyril's 11/11 recette on a Cloudflare tunnel —
// the banner still said "Install BouclePro"), never a hostname. One read,
// at the top of the file, rather than a call inside every string.
$appName = \App\Support\Pwa\PwaAppName::current();

return [

    'title' => "Install {$appName} on your phone",
    'promise' => 'Your Loops, a thumb away.',

    'benefits' => [
        'home_screen' => [
            'title' => 'Your Loops in one tap',
            'body' => 'An icon on your home screen, no address to type.',
        ],
        'fullscreen' => [
            'title' => 'The whole screen for your conversations',
            'body' => 'No address bar, more room to read and write.',
        ],
        'faster' => [
            'title' => 'Faster to open',
            'body' => 'The app shell stays cached on your device.',
        ],
    ],

    'install' => "Install {$appName}",
    'continue_in_browser' => 'Continue in the browser',

    // On `/installer` the secondary action is a RETURN, not a dismissal.
    'back_to_site' => 'Back to the site',

    'installing_title' => "{$appName} is installing on your phone",
    'installing_body' => "You are still in your browser. Find its icon on your home screen or in your apps, then open it.",
    'installing_note' => "On first launch, {$appName} will offer to turn on important notifications.",

    // ── When `/installer` has nothing to offer ───────────────────────────────
    //
    // The `PWA_INSTALL_POLICY=off` kill-switch emits no screen at all. But
    // `/installer` is still a URL: a bookmark, a link in an email, a typed
    // address. Without these two strings the page rendered BLANK — the same
    // defect the 31/10/2026 recette surfaced through another door. A reachable
    // page must always say something.
    'unavailable_title' => 'Installation unavailable',
    'unavailable_body' => "Installing {$appName} on your device is not offered right now. The site stays available in your browser.",

    'manual_title' => "To install {$appName}",

    'ios' => [
        'intro' => 'Three taps, from Safari:',
        'step_share' => 'Tap the Share button, at the bottom of the screen',
        'step_add' => 'Choose "Add to Home Screen"',
        'step_confirm' => 'Tap "Add", then open the '.$appName.' icon',
        'disclaimer' => 'Labels may differ depending on your iOS version.',
    ],

    'ios_other_browser' => [
        'title' => 'Open this page in Safari',
        'body' => 'On iPhone and iPad, adding to the home screen goes through Safari.',
    ],

    'generic' => [
        'intro' => 'From your browser menu:',
        'step_menu' => 'Open the browser menu',
        'step_add' => 'Choose "Install app" or "Add to Home screen"',
    ],

    'banner' => [
        'title' => "{$appName} installs on your phone",
        'body' => 'Your Loops in one tap, full screen.',
        'action' => 'Install',
        'dismiss' => 'Dismiss',
    ],

    'dialog_label' => "Install the {$appName} app",
    'close' => 'Close',

    // TASK-1745 — the first-launch prompt, in the installed app. The body
    // names the events the catalogue actually covers (T1744), and says how
    // to undo BEFORE asking.
    'first_run_title' => "Turn on notifications?",
    'first_run_body' => "{$appName} will let you know when you are invited, added to a Loop, or when your request is accepted. You can change your mind at any time in your settings.",
    'first_run_accept' => "Turn on notifications",
    'first_run_later' => "Later",
    'first_run_failed' => "Notifications could not be turned on. You can try again from your settings.",

    // TASK-1745, 03/11 recipe — one body per permission state. `granted`
    // must say no new prompt will appear; `denied` explains the way back
    // (site settings) instead of offering a button that can only fail.
    'first_run_granted_body' => "Permission is already granted: this device just needs to be switched on — no new prompt will appear.",
    'first_run_denied_body' => "Notifications are blocked for {$appName} in your browser. To re-enable them: open the site settings (icon next to the address, or Settings › Notifications), allow notifications, then come back here.",
    'first_run_understood' => "Got it",

    // TASK-1822 — the subscription exists, permission may well be granted,
    // but it was created with a security key that is no longer in use: the
    // browser drops the messages without showing anything. NEVER word this
    // as “enable notifications” — the person believed they were on, and
    // they were.
    'first_run_stale_key_body' => "This device was subscribed with a security key that is no longer in use: notifications no longer reach it, even though they look enabled. Reactivating is enough to restore delivery.",
    'first_run_stale_key_action' => "Reactivate notifications",

    /*
     * TASK-1745, recette du 03/11 — les gestes NUMEROTES de la carte de
     * confirmation. Une liste se parcourt du coin de l'oeil ; une phrase
     * continue oblige a tout lire pour trouver ce qu'on doit faire.
     *
     * Le dernier n'est pas un geste mais une ANNONCE, et il a sa variante
     * `_done` : une reinstallation sur un appareil deja abonne ne doit pas
     * promettre une question qui ne sera pas posee.
     */
    'installing_step_wait' => "Android is finishing the installation, it takes a few seconds.",
    'installing_step_icon' => "Find the {$appName} icon on your home screen or in your apps.",
    'installing_step_open' => "Open it: you are still in your browser, not in the app.",
    'installing_step_notifications' => "On first launch, {$appName} will offer to turn on important notifications.",
    'installing_step_notifications_done' => "Notifications are already on for this device: nothing to redo.",

    /*
    | TASK-1878 — "PWA diagnostics" console.
    */
    'diagnostic' => [
        'title' => 'PWA diagnostics',
        'intro' => "This page measures the state of {$appName} on this device. It changes nothing: no setting, no Service Worker, no pending share.",
        'link_from_device' => 'Full PWA diagnostics',
        'back_device' => '← Settings on this device',
        'auto_heading' => 'Automatic measurements',
        'manual_heading' => 'Manual checks',
        'limits' => "Limits: being open in the installed app does not prove Android registered {$appName} in its Share menu. A technical simulation does not replace a real test on Android. A send accepted by the provider is not proof of delivery.",
        'measuring' => 'Measuring…',

        'status' => [
            'ok' => 'OK',
            'fail' => 'FAILED',
            'unverifiable' => 'NOT VERIFIABLE',
            'untested' => 'NOT TESTED',
        ],
        'yes' => 'Yes',
        'no' => 'No',
        'none' => 'none',
        'no_answer' => 'no answer',

        'sections' => [
            'installation' => 'Installation',
            'service_worker' => 'Service Worker',
            'notifications' => 'Notifications',
            'sharing' => 'Android sharing',
        ],

        'rows' => [
            'inst_origin' => 'Origin',
            'inst_secure' => 'Secure connection (HTTPS)',
            'inst_standalone' => 'Open in the installed app',
            'inst_manifest_link' => 'Manifest declared in the page',
            'inst_manifest_valid' => 'Manifest served and valid',
            'inst_name' => 'App name',
            'inst_support' => 'Browser PWA features',
            'sw_registration' => 'Service Worker registered',
            'sw_controller' => 'Service Worker controlling the page',
            'sw_scope' => 'Scope',
            'sw_version' => 'Active version',
            'sw_waiting' => 'Update waiting',
            'push_permission' => 'Notification permission',
            'push_subscription' => 'Subscription on this device',
            'push_server' => 'Known to the server',
            'push_key' => 'Subscription VAPID key',
            'push_events' => 'Events open to Push',
            'push_local' => 'Local display test',
            'push_simulate' => 'Delivery simulation (Service Worker)',
            'push_real' => 'Real Web Push test',
            'share_standalone' => 'Open in the installed app',
            'share_declared' => "Sharing to {$appName} declared",
            'share_params' => 'GET method and url, text, title parameters',
            'share_server' => 'Declaration expected by the server',
            'share_route' => 'Receiving path (/partage → choose a Loop)',
            'share_simulation' => 'Technical share simulation (without Android)',
            'share_pending' => 'Received share waiting',
            'share_native' => 'Listed in the Android Share menu',
        ],

        'values' => [
            'name_expected' => '%s (expected: %s)',
            'sw_version' => '%s (published: %s)',
            'support' => 'Service Worker %s · Push %s · Notifications %s · Clipboard %s',
            'manifest_ok' => 'read, complete',
            'events' => '%s event(s)',
            'simulation_ok' => 'a "title + link" share is understood',
            'not_run' => 'not run yet',
            'real_sent' => 'send accepted by the provider — delivery to be confirmed manually',
            'native_manual' => 'check by hand (test below)',
        ],

        'help' => [
            'inst_secure' => 'Without HTTPS, neither the Service Worker nor notifications work. Open the address with https://.',
            'inst_standalone' => "This page is open in the browser. To diagnose the app, open {$appName} from its icon, then come back here (Notifications → Settings on this device → Full PWA diagnostics).",
            'inst_manifest_link' => 'The page declares no manifest: the browser cannot offer installation. Report it to the team with this report.',
            'inst_manifest_valid' => 'The manifest could not be read or is incomplete (%s). Report it to the team with this report.',
            'inst_name' => 'The served name differs from the expected one: the installed icon may belong to another version. Uninstall it, then reinstall from this address.',
            'inst_support' => 'This browser does not support every required feature. On Android, use an up-to-date Chrome.',
            'sw_registration' => 'No Service Worker: reload the page. If it is still missing, check you are not in private browsing.',
            'sw_controller' => 'The page is not controlled yet (first load or hard reload). Reload the page normally.',
            'sw_scope' => 'The scope does not cover the whole site: notifications and sharing may open outside the app. Report it to the team.',
            'sw_version' => "The active copy is older than the published one. Close every {$appName} window and the app, then reopen it.",
            'sw_version_silent' => "The active copy does not answer the diagnostic: it predates this protocol. Close every {$appName} window and the app, then reopen it.",
            'sw_waiting' => "A new version is waiting for every {$appName} window to close. Close them all, app included, then reopen. This page does not force the update.",
            'push_permission' => "Notifications are blocked. Android: Settings → Apps → {$appName} (or Chrome) → Notifications → Allow.",
            'push_permission_default' => 'Permission not requested yet: use "Enable" in Settings on this device.',
            'push_subscription' => 'No subscription: enable notifications in Settings on this device.',
            'push_server' => 'The server does not know this subscription: disable then re-enable notifications in Settings on this device.',
            'push_key' => 'The subscription was created with an old key: notifications will be dropped silently. Disable then re-enable.',
            'push_key_hidden' => 'This browser does not expose the subscription key: the match cannot be measured.',
            'push_events' => 'No event is ticked for the Push channel: nothing will be sent. Tick some in your preferences.',
            'push_local' => 'Local display fails: the cause is on the device (permission, notifications off in Android, Do Not Disturb).',
            'push_simulate' => 'The Service Worker does not show a simulated payload: close and reopen the app to load the published version.',
            'push_real' => 'The real send fails: follow the message shown by the test, then re-enable notifications if needed.',
            'share_standalone' => "Open {$appName} from its icon to test sharing in real conditions.",
            'share_declared' => "The served manifest does not declare sharing to /partage: Android cannot offer {$appName}. Report it to the team.",
            'share_params' => 'The share declaration is incomplete (method or parameters). Report it to the team.',
            'share_server' => 'The server reference manifest no longer matches the expected declaration. Report it to the team.',
            'share_route' => 'The share receiving path is unavailable on this server. Report it to the team.',
            'share_simulation' => 'The server can no longer extract a link from a share. Report it to the team.',
            'share_pending' => 'A received share is waiting: it will show on the choose-a-Loop page. This diagnostic did not change it.',
            'share_native' => 'No web API can check this. Run the manual test below. In Chrome on Android, chrome://webapks lists installed apps.',
            'manual_failed' => 'Manual check failed: see the explanation below.',
        ],

        'manual' => [
            'intro' => 'These points cannot be measured from a web page. Run the test, then answer: your answers stay on this device and go into the report.',
            'steps_heading' => 'Test Android sharing',
            'steps' => [
                "Install {$appName} from Chrome (⋮ menu → Install app), then open it from its icon.",
                'In Chrome, open a web page, then ⋮ menu → Share.',
                "Look for {$appName} in the list (sometimes under \"More\").",
                "Tap {$appName}: the \"Share to a Loop\" page should show the link.",
                'Check you can choose a Loop. For a mere test, do not publish: go back.',
                'Come back here and answer the questions.',
            ],
            'open_choose' => 'Open the choose-a-Loop page (without publishing)',
            'answer_yes' => 'Yes',
            'answer_no' => 'No',
            'answer_untested' => 'Not tested',
            'questions' => [
                'm_push_received' => 'I received the real Web Push test notification',
                'm_share_menu' => "{$appName} is listed in the Android Share menu",
                'm_share_transmitted' => 'When it opens, the shared link is shown',
                'm_share_choose' => 'The choose-a-Loop page shows',
                'm_share_loop' => 'I can choose an allowed Loop',
            ],
            'help' => [
                'm_push_received' => "Send accepted but nothing received: check Android notifications for {$appName} and Chrome, Do Not Disturb and battery saver. Compare with the delivery simulation: if it shows, the fault is in delivery.",
                'm_share_menu' => "Installed as a mere shortcut (not as an app), {$appName} is not registered as a share target: uninstall it, then reinstall from Chrome (\"Install app\"). Check in chrome://webapks. The menu may also put it under \"More\".",
                'm_share_transmitted' => 'The source app may have sent only text without a link. Try again from Chrome (Share a web page).',
                'm_share_choose' => 'Note the screen you got (sign-in asked? error?) and attach this report.',
                'm_share_loop' => 'No Loop offered: you are not a member of any Loop where posting is allowed.',
            ],
        ],

        'report' => [
            'copy' => 'Copy the diagnostic report',
            'copied' => 'Report copied. Paste it into your message to the team.',
            'copy_failed' => 'Copy is not possible here: select the text below and copy it.',
            'title' => "{$appName} PWA diagnostic report",
            'date' => 'Date',
            'environment' => 'Environment',
            'version' => 'App version',
            'platform' => 'Platform',
            'install_mode' => 'Detected install mode',
            'mode_standalone' => 'installed app (standalone)',
            'mode_browser' => 'browser',
            'causes' => 'Probable causes and next steps',
            'no_cause' => 'No anomaly measured.',
            'unknown' => 'unknown',
        ],
    ],
];
