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
    'mark_unread' => 'Mark as unread',

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

    // TASK-1746 (CDC §7A) — sections named by what the event ASKS of the
    // person, never by the technical object it refers to. The third one is
    // provisioned for §9.2 and stays empty; an empty section is not shown.
    'intentions' => [
        'sollicitation_directe' => [
            'title' => "When someone reaches out to me",
            'hint' => "Addressed to you personally, and waiting for your reply.",
        ],
        'changements' => [
            'title' => "Invitations and changes",
            'hint' => "Things that concern you without asking anything: you are kept informed.",
        ],
        'sollicitation_pertinente' => [
            'title' => "Relevant requests",
            // TASK-1747 — widened: the section now also carries requests
            // posted in the Boucles you belong to, not just future matches.
            'hint' => "Requests you could answer: those from your Boucles, and those matching what you know how to do.",
        ],
    ],
    'preferences_mandatory_hint' => 'This notification is addressed to you personally and calls for a reply: it cannot be turned off.',
    'preferences_link' => 'Settings',
    'preferences_back' => 'My notifications',
    'channel_in_app' => 'In the app',
    'channel_email' => 'By email',
    // TASK-1740 — third channel. The label names the DEVICE, not the
    // technique: "web push" means nothing to the person setting it.
    'channel_web_push' => 'On my devices',

    // TASK-1740 — text that lands on a LOCKED screen.
    //
    // CDC 6.4: no person's name, no excerpt, no CONFIDENTIAL Loop name. It
    // must stay true in front of anyone — the detail is read AFTER the tap,
    // on a page the server re-authorises.
    //
    // TASK-1744 nuances it the way the CDC itself does: a PUBLIC Loop's name
    // may travel (6.2 says "no PRIVATE Loop name"), and the deliverer is the
    // only place that decides — see `nomDeBouclePublique()`.
    'push_title_fallback' => 'BouclePro',
    'push_body_generic' => 'Tap to see.',
    /*
     * TASK-1744 — a BODY per event.
     *
     * "Tap to see" said nothing the title did not already say. It
     * remains the last resort, for a key without its own sentence.
     *
     * These sentences are used ONLY when the Loop is not public:
     * when it is, the deliverer puts its NAME here instead, which
     * is what the person is looking for.
     */
    'push_body' => [
        'loop_mention_created' => 'Open BouclePro to read the message.',
        'loop_invitation' => 'Open BouclePro to reply.',
        'loop_member_added' => 'You can now take part.',
        'loop_join_request_accepted' => 'You can now take part.',
        'blog_co_author_added' => 'Open the article to get started.',

        // TASK-1747 — names NEITHER the Boucle NOR the person: a request
        // may be posted in a private Boucle, and a Push body shows on a
        // locked screen.
        'loop_help_request_created' => 'Open BouclePro to see if you can help.',

        // TASK-1751 — names neither the person nor the listing.
        'transaction_proposal_created' => 'Open BouclePro to see the proposal.',
    ],

    'push_title' => [
        // TASK-1772 — ni le nom de la personne, ni celui de la Boucle :
        // ce texte s'affiche sur un ecran VERROUILLE. Le corps sera
        // complete du nom de la Boucle si elle est PUBLIQUE, par
        // `nomDeBouclePublique()`, et seulement dans ce cas.
        'loop_mention_created' => 'You were mentioned',
        'loop_invitation' => 'An invitation is waiting',
        'loop_member_added' => 'You have joined a Boucle',
        'loop_join_request_accepted' => 'Your request was accepted',

        // TASK-1744 — the fourth key opened to Push. The title does not name
        // the article: a co-written draft has no reason to show up in front of
        // anyone.
        'blog_co_author_added' => 'You have been added to an article',

        // TASK-1747 — the channel is OFF by default, but the label must
        // exist: the coherence guard requires it as soon as a channel is
        // declared.
        'loop_help_request_created' => 'A request for help in your Boucle',

        // TASK-1751 — shown on a LOCKED screen (CDC §6.4/6.5).
        'transaction_proposal_created' => 'Someone replied to your listing',
    ],

    'keys' => [
        'loop_mention_created' => 'You were mentioned in a Loop',
        // TASK-1761 — the REQUEST, owner side.
        'loop_join_request_created' => 'Someone is asking to join your Loop',
        // TASK-1747 (CDC §8.1)
        'loop_help_request_created' => 'A request for help was posted',

        // TASK-1751 — one key for both directions of the exchange.
        'transaction_proposal_created' => 'Someone replied to your listing',

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
     * These keys are a SUBSET of `keys`: only notifications whose OBJECT can be
     * named. TASK-1757 — that meant "a Loop" until now; a help exchange is
     * named too (first name, listing title, time), so the placeholder is no
     * longer always `:loop`.
     */
    'keys_named' => [
        // TASK-1772 — nommee UNIQUEMENT ici : le Centre est derriere
        // l'authentification. `push_title` et le sujet d'email restent
        // generiques (ecran verrouille, apercu de boite mail).
        'loop_mention_created' => '“:prenom” mentioned you in the Loop “:loop”',
        // TASK-1757 — see the French file for why this surface only.
        'transaction_proposal_created' => ':prenom replies to your listing “:titre” — :temps',
        'loop_invitation' => 'Invitation to join the :loop Loop',
        'loop_member_added' => 'Added to the :loop Loop',
        'loop_join_request_accepted' => 'Your request to join :loop was accepted',
        'loop_join_request_rejected' => 'Your request to join :loop was not accepted',
    ],

    // TASK-1725 — « Open » did not say what. The label now follows the object.
    'open_loop' => 'Open the Loop',

    // Fallback when a catalogue key has no label yet. It must never show the
    // technical key: that means nothing to a member.
    // TASK-1772 — a message can be a photo only: `loop_messages.body` is
    // then empty, and the quote block would look broken.
    'mention_email_excerpt_empty' => '(message with no text)',

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
        // TASK-1772 — REPLI. Le rendu passe normalement par le gabarit
        // administrable `loop_mention_created` ; ceci ne sert que si le
        // gabarit est desactive ou absent.
        'loop_mention_created' => [
            'subject' => 'You were mentioned in a Loop',
            // TASK-1772 — see the French file: both the excerpt and the
            // settings link stay placeholders, never hardcoded addresses.
            'body' => '<p>Hello,</p><p>Someone mentioned you in a message from a Loop.</p><blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #ccc;white-space:pre-line">:extrait</blockquote><p><a href=":url">Read the message</a></p><p>If the link does not work, copy this address into your browser:<br>:url</p><p>No rush: the message will wait for you.</p><p>See you soon,<br>The BouclePro team</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">To stop receiving this email, go to <a href=":reglages">Notification settings</a>.</p>',
        ],
        'loop_join_request_created' => [
            'subject' => 'Someone is asking to join your Loop',
            'body' => '<p>Hello,</p>'
                .'<p>Someone is asking to join a Loop you are responsible for.</p>'
                .'<p><a href=":url">View the request</a></p>'
                .'<p>If the link does not work, copy this address into your browser:<br>:url</p>'
                .'<p>See you soon,<br>The BouclePro team</p>',
        ],
        // TASK-1751 — FALLBACK, used when the administrable template is
        // missing or disabled. The coherence suite requires it as soon as
        // the `email` channel is declared.
        'transaction_proposal_created' => [
            'subject' => 'Someone replied to your listing',
            'body' => '<p>Hello,</p>'
                .'<p>Someone has just replied to a listing you published on BouclePro.</p>'
                .'<p><a href=":url">View the exchange</a></p>'
                .'<p>If the link does not work, copy this address into your browser:<br>:url</p>'
                .'<p>See you soon,<br>The BouclePro team</p>',
        ],
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
