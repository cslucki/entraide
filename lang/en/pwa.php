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

return [

    'title' => 'Install BouclePro on your phone',
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

    'install' => 'Install BouclePro',
    'continue_in_browser' => 'Continue in the browser',

    'installing_title' => 'Almost there',
    'installing_body' => 'Open BouclePro from your home screen.',

    'manual_title' => 'To install BouclePro',

    'ios' => [
        'intro' => 'Three taps, from Safari:',
        'step_share' => 'Tap the Share button, at the bottom of the screen',
        'step_add' => 'Choose "Add to Home Screen"',
        'step_confirm' => 'Tap "Add", then open the BouclePro icon',
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
        'title' => 'BouclePro installs on your phone',
        'body' => 'Your Loops in one tap, full screen.',
        'action' => 'Install',
        'dismiss' => 'Dismiss',
    ],

    'dialog_label' => 'Install the BouclePro app',
    'close' => 'Close',
];
