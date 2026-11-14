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
];
