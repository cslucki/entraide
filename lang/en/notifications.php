<?php

return [
    // The Center
    'title' => 'My notifications',
    'subtitle' => 'What BouclePro wanted to bring to your attention.',

    // Filters
    'filter_all' => 'All',
    'filter_unread' => 'Unread',

    // Actions
    'mark_read' => 'Mark as read',
    'mark_all_read' => 'Mark all as read',

    // Empty states — TWO, deliberately. An empty page and an empty filter do
    // not say the same thing: the first says "nothing awaits you", the second
    // "nothing HERE, but there is elsewhere". Merging them would suggest an
    // empty inbox when it is not.
    'empty_title' => 'No notifications',
    'empty_body' => 'You will be told here when something concerns you.',
    'filter_empty_title' => 'All caught up',
    'filter_empty_body' => 'No unread notifications right now.',

    // Read state
    'unread_badge' => 'Unread',

    // Labels per catalogue key. Dots in the key become underscores:
    // `loop.invitation` -> `keys.loop_invitation`.
    'keys' => [
        'loop_invitation' => 'Invitation to join a Loop',
    ],

    // Fallback when a catalogue key has no label yet. It must never show the
    // technical key: that means nothing to a member.
    'key_fallback' => 'Notification',
];
