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
    'target_unreachable' => 'This item is no longer available.',
    'open' => 'Open',
    'unread_badge' => 'Unread',

    // Labels per catalogue key. Dots in the key become underscores:
    // `loop.invitation` -> `keys.loop_invitation`.
    'preferences_title' => 'Notification settings',
    'preferences_subtitle' => 'Choose what BouclePro sends you.',
    'preferences_save' => 'Save',
    'preferences_saved' => 'Your settings have been saved.',
    'preferences_mandatory' => 'Always on',
    'preferences_mandatory_hint' => 'This notification is addressed to you personally and calls for a reply: it cannot be turned off.',
    'preferences_link' => 'Settings',
    'preferences_back' => 'My notifications',
    'channel_in_app' => 'In the app',
    'channel_email' => 'By email',

    'keys' => [
        // TASK-1702
        'blog_co_author_added' => 'You were added as a co-author of an article',

        // TASK-1697
        'blog_comment_created' => 'A new comment on your article',
        'loop_invitation' => 'Invitation to join a Loop',
        'loop_member_added' => 'Added to a Loop',

        // TASK-1381 — two labels, because these are two facts. The member must
        // know what happened without having to open anything.
        'loop_join_request_accepted' => 'Your request to join was accepted',
        'loop_join_request_rejected' => 'Your request to join was not accepted',
    ],

    /*
     * TASK-1725 — the SAME facts, naming the Loop.
     *
     * A separate group rather than a `:loop` placeholder inside `keys`: the
     * name is not always known at render time (deleted Loop, other tenant), and
     * a sentence with a hole fed an empty string would read « Added to the Loop
     * "" ». Both forms coexist; the view picks according to what it has.
     *
     * These keys are a SUBSET of `keys`: only notifications carrying a Loop.
     */
    'keys_named' => [
        'loop_invitation' => 'Invitation to join the :loop Loop',
        'loop_member_added' => 'Added to the :loop Loop',
        'loop_join_request_accepted' => 'Your request to join :loop was accepted',
        'loop_join_request_rejected' => 'Your request to join :loop was not accepted',
    ],

    // TASK-1725 — « Open » did not say what. The label now follows the object.
    'open_loop' => 'Open the Loop',

    // Fallback when a catalogue key has no label yet. It must never show the
    // technical key: that means nothing to a member.
    'key_fallback' => 'Notification',

    /*
    |--------------------------------------------------------------------------
    | Notification emails (TASK-1378)
    |--------------------------------------------------------------------------
    |
    | The sub-array is named after the catalogue key with DOTS REPLACED:
    | `loop.invitation` becomes `loop_invitation`. A dot would be read as a
    | group hierarchy by the translation resolver, making the key unreachable
    | for a reason unrelated to its content.
    |
    | `:url` is rendered TWICE by the deliverer: with the real address for the
    | send, with a redacted marker for archiving. Do not hard-code a link here:
    | those two renders are what protect the token.
    |
    */
    'email' => [
        'loop_invitation' => [
            'subject' => 'You have been invited to join a loop',
            'body' => '<p>Hello,</p>'
                .'<p>You have been invited to join a loop on BouclePro.</p>'
                .'<p><a href=":url">View the invitation</a></p>'
                .'<p>If the link does not work, copy this address into your browser:<br>:url</p>'
                .'<p>See you soon,<br>The BouclePro team</p>',
        ],
        'loop_member_added' => [
            'subject' => 'You have been added to a Loop',
            'body' => '<p>Hello,</p>'
                .'<p>You have been added to a Loop on BouclePro. You are now a member.</p>'
                .'<p><a href=":url">Open the Loop</a></p>'
                .'<p>If the link does not work, copy this address into your browser:<br>:url</p>'
                .'<p>See you soon,<br>The BouclePro team</p>',
        ],
        // TASK-1725 — ultimate fallback for an ACCEPTED join request. See the
        // French file for the three layers; deliberately neutral, because at
        // this level neither the Loop nor the decider is known any more.
        'loop_join_request_accepted' => [
            'subject' => 'Your request to join a Loop has been accepted',
            'body' => '<p>Hello,</p>'
                .'<p>Your request to join a Loop on BouclePro has been accepted. You are now a member.</p>'
                .'<p><a href=":url">Open the Loop</a></p>'
                .'<p>If the link does not work, copy this address into your browser:<br>:url</p>'
                .'<p>See you soon,<br>The BouclePro team</p>',
        ],
    ],
];
