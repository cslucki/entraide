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
            'title' => 'When someone reaches out to me',
            'hint' => 'Addressed to you personally, and waiting for your reply.',
        ],
        'changements' => [
            'title' => 'Invitations and changes',
            'hint' => 'Things that concern you without asking anything: you are kept informed.',
        ],
        'sollicitation_pertinente' => [
            'title' => 'Relevant requests',
            // TASK-1747 — widened: the section now also carries requests
            // posted in the Boucles you belong to, not just future matches.
            'hint' => 'Requests you could answer: those from your Boucles, and those matching what you know how to do.',
        ],
    ],
    'preferences_mandatory_hint' => 'This notification is addressed to you personally and calls for a reply: it cannot be turned off.',

    // TASK-1775 (CDC B1) — collective settings, Loop by Loop.
    'loops' => [
        'title' => 'My Loops',
        'hint' => 'Help requests published in your Loops are set Loop by Loop.',
        'master_label' => 'Receive notifications',
        'empty_message' => "You haven't joined a Loop yet. Join one to take part in mutual aid and receive help requests.",
        'join_cta' => 'Join a loop',
    ],
    'preferences_link' => 'Settings',
    'preferences_back' => 'My notifications',
    'channel_in_app' => 'In the app',
    'channel_email' => 'By email',
    // TASK-1740 — third channel. The label names the DEVICE, not the
    // technique: "web push" means nothing to the person setting it.
    // TASK-1775 (Cyril's request, 07/11) — replaces "On my devices": names
    // the installed APP, not the physical device.
    'channel_web_push' => 'On the downloaded app',

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
        'blog_comment_created' => 'Open the article to read the comment.',
        'blog_co_author_added' => 'Open the article to get started.',
        // TASK-1786 — generic on purpose: a push shows on a LOCKED SCREEN, so
        // naming the person would expose someone's mutual aid to whoever walks
        // past the phone. The full story lives in `keys_named`, behind auth.
        'entraide_comment_created' => 'Open BouclePro to read the suggestion.',

        // TASK-1747 — names NEITHER the Boucle NOR the person: a request
        // may be posted in a private Boucle, and a Push body shows on a
        // locked screen.
        'loop_help_request_created' => 'Open BouclePro to see if you can help.',

        // TASK-1751 — names neither the person nor the listing.
        'transaction_proposal_created' => 'Open BouclePro to see the proposal.',

        // TASK-1802 — names neither the Boucle nor the event: a Push shows on
        // a locked screen, and the Boucle may be private.
        'loop_event_created' => 'Open BouclePro to see the details.',

        // TASK-1810 — neither the person nor the item's context: a Push
        // shows on a LOCKED screen, same doctrine as its neighbours.
        'feed_post_comment_created' => 'Open the Feed to read the comment.',
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
        'blog_comment_created' => 'A new comment on your article',
        'blog_co_author_added' => 'You have been added to an article',

        // TASK-1786 — names neither the person nor the listing.
        'entraide_comment_created' => 'New suggestion on your mutual aid',

        // TASK-1747 — the channel is OFF by default, but the label must
        // exist: the coherence guard requires it as soon as a channel is
        // declared.
        'loop_help_request_created' => 'A request for help in your Boucle',

        // TASK-1751 — shown on a LOCKED screen (CDC §6.4/6.5).
        'transaction_proposal_created' => 'Someone replied to your help request',

        // TASK-1802 — names neither the Boucle nor the event, same reason as
        // its collective neighbours: a Push shows on a locked screen.
        'loop_event_created' => 'A new event in your Loop',

        // TASK-1810 — same doctrine: neither the person nor the item's
        // context, on a locked screen.
        'feed_post_comment_created' => 'New comment on the Feed',
    ],

    'keys' => [
        'loop_mention_created' => 'You were mentioned in a Loop',
        // TASK-1761 — the REQUEST, owner side.
        'loop_join_request_created' => 'Someone is asking to join your Loop',
        // TASK-1747 (CDC §8.1)
        'loop_help_request_created' => 'A request for help was posted',

        // TASK-1751 — one key for both directions of the mutual aid.
        'transaction_proposal_created' => 'Someone replied to your help request or offer',

        // TASK-1702
        'blog_co_author_added' => 'You were added as a co-author of an article',

        // TASK-1697
        'blog_comment_created' => 'A new comment on your article',

        // TASK-1786 — the label shown in notification settings.
        'entraide_comment_created' => 'Comments on my offers and help requests',
        'loop_invitation' => 'Invitation to join a Loop',
        'loop_member_added' => 'Added to a Loop',

        // TASK-1381 — two labels, because these are two facts. The member must
        // know what happened without having to open anything.
        'loop_join_request_accepted' => 'Your request to join was accepted',
        'loop_join_request_rejected' => 'Your request to join was not accepted',

        // TASK-1802 (Cyril's request) — a new event proposed in a Loop.
        'loop_event_created' => 'A new event was proposed',

        // TASK-1810 (demande Cyril, 12/11) — comments or reactions on my Feed items.
        'feed_post_comment_created' => 'Comments on my Feed items',
        'feed_post_reaction_created' => 'Reactions on my Feed items',
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
        // TASK-1797 — separate key, not a rewrite of the one above: a
        // collective mention never named you personally. Same
        // `notification_key`, same recipient, same event — only the TEXT
        // differs, read by `sujetsDesMentions()` when the message carries
        // `mention_all`.
        'loop_mention_all_created' => '“:prenom” mentioned all the members of the Loop “:loop”',
        // TASK-1757 — see the French file for why this surface only.
        'transaction_proposal_created' => ':prenom replies to “:titre” — :temps',
        // TASK-1786 — named ONLY here. `:type` carries "mutual aid offer" or
        // "help request", resolved by the controller, which is what lets ONE
        // key serve both listing types.
        'entraide_comment_created' => ':prenom commented on your :type “:titre”',
        'loop_invitation' => 'Invitation to join the :loop Loop',
        'loop_member_added' => 'Added to the :loop Loop',
        'loop_join_request_accepted' => 'Your request to join :loop was accepted',
        'loop_join_request_rejected' => 'Your request to join :loop was not accepted',

        // TASK-1775 (CDC C4) — named ONLY here, same reasoning as the entries
        // above.
        'loop_help_request_created' => 'A help request was published in the :loop Loop',

        // TASK-1802 — named ONLY here, same reasoning as the entries above.
        'loop_event_created' => 'A new event was proposed in the :loop Loop',
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
    'blog_comment_email_excerpt_empty' => '(comment without text)',
    // TASK-1786 — meme repli pour un commentaire d'entraide sans texte.
    'entraide_comment_email_excerpt_empty' => '(comment without text)',
    // TASK-1810 — same fallback for a Feed comment without text.
    'feed_post_comment_email_excerpt_empty' => '(comment without text)',

    'email' => [
        // TASK-1772 — REPLI. Le rendu passe normalement par le gabarit
        // administrable `loop_mention_created` ; ceci ne sert que si le
        // gabarit est desactive ou absent.
        // TASK-1784 — REPLI. Le rendu passe normalement par le gabarit
        // administrable `blog_comment_created` ; ceci ne sert que si le
        // gabarit est desactive ou absent.
        //
        // `:url`, `:reglages`, `:titre` et `:extrait` restent des JETONS.
        // Une adresse en dur ici echapperait a l'expurgation du livreur et
        // finirait telle quelle dans `email_logs` — garde
        // `TASK1379…::test_every_email_body_uses_the_url_placeholder`.
        // TASK-1786 — language FALLBACK only. The authoritative template is the
        // administrable `entraide_comment_created` (seeder + migration); this is
        // used only if it is missing. Generic subject: a mailbox preview is read
        // without unlocking, same doctrine as `push_title`.
        // TASK-1808 §6 — fallback. The authoritative template is the
        // administrable `entraide_evaluation_invite` (seeder + migration);
        // this only serves if it is missing.
        'entraide_evaluation_invite' => [
            'subject' => 'A quick word on the help you received?',
            'body' => '<p>Hello,</p><p>Your mutual aid with :aidant is complete.</p><p>You can leave them a review.</p><p><a href=":url">Leave a review</a></p><p>If the link does not work, copy this address into your browser:<br>:url</p><p>Thank you for keeping mutual aid alive on BouclePro.</p>',
        ],

        // TASK-1809 §4 — fallback. The authoritative template is the
        // administrable `entraide_review_received` (seeder + migration);
        // this only serves if it is missing.
        'entraide_review_received' => [
            'subject' => ':aide left you a review!',
            'body' => '<p>Hello,</p><p>:aide left you a review for your mutual aid.</p><p style="font-size:20px;letter-spacing:2px">:etoiles <span style="font-size:14px;color:#777">(:note)</span></p><p>:commentaire</p><p>Thank you for keeping mutual aid alive on BouclePro.</p>',
        ],

        'entraide_comment_created' => [
            'subject' => 'A member commented on your mutual aid',
            'body' => '<p>Hello,</p><p>A member left a comment on your mutual aid.</p><p><a href=":url">View the comment</a></p><p>If the link does not work, copy this address into your browser:<br>:url</p><p>A comment commits to nothing: no mutual aid started, no accepted help, no promised time.</p><p>See you soon,<br>The BouclePro team</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">To stop receiving this email, go to <a href=":reglages">Notification settings</a>.</p>',
        ],

        'blog_comment_created' => [
            'subject' => 'Someone commented on your article',
            'body' => '<p>Hello,</p><p>Someone left a comment on your article <strong>:titre</strong>.</p><blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #ccc;white-space:pre-line">:extrait</blockquote><p><a href=":url">Read the comment</a></p><p>If the link does not work, copy this address into your browser:<br>:url</p><p>No rush: the comment will wait for you.</p><p>See you soon,<br>The BouclePro team</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">To stop receiving this email, go to <a href=":reglages">Notification settings</a>.</p>',
        ],

        // TASK-1810 — fallback, same doctrine: `:prenom` (the commenter's
        // first name), `:contexte` (what the Feed card shows) and
        // `:extrait` (the comment text) stay PLACEHOLDERS, never a
        // hardcoded address.
        'feed_post_comment_created' => [
            'subject' => 'Comment on the Feed',
            'body' => '<p>Hello,</p><p><strong>:prenom</strong> just commented on the Feed about something that concerns you: <strong>:contexte</strong></p><blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #ccc;white-space:pre-line">:extrait</blockquote><p><a href=":url">View in the Feed</a></p><p>If the link does not work, copy this address into your browser:<br>:url</p><p>No rush: you don’t need to sign in to read it.</p><p>See you soon,<br>The BouclePro team</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">To stop receiving this email, go to <a href=":reglages">Notification settings</a>.</p>',
        ],
        'loop_mention_created' => [
            'subject' => 'You were mentioned in a Loop',
            // TASK-1772 — see the French file: both the excerpt and the
            // settings link stay placeholders, never hardcoded addresses.
            'body' => '<p>Hello,</p><p>Someone mentioned you in a message from a Loop.</p><blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #ccc;white-space:pre-line">:extrait</blockquote><p><a href=":url">Read the message</a></p><p>If the link does not work, copy this address into your browser:<br>:url</p><p>No rush: the message will wait for you.</p><p>See you soon,<br>The BouclePro team</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">To stop receiving this email, go to <a href=":reglages">Notification settings</a>.</p>',
        ],
        // TASK-1797 — fallback for the COLLECTIVE mention. Mirrors the
        // French file: never says "mentioned you", nobody was named.
        'loop_mention_all_created' => [
            'subject' => 'All members of a Loop were mentioned',
            'body' => '<p>Hello,</p><p>Someone mentioned all the members of a Loop you belong to.</p><blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #ccc;white-space:pre-line">:extrait</blockquote><p><a href=":url">Read the message</a></p><p>If the link does not work, copy this address into your browser:<br>:url</p><p>No rush: the message will wait for you.</p><p>See you soon,<br>The BouclePro team</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">To stop receiving this email, go to <a href=":reglages">Notification settings</a>.</p>',
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
            'subject' => 'Someone replied to your help request or offer',
            'body' => '<p>Hello,</p>'
                .'<p>Someone has just replied to a help request or offer you published on BouclePro.</p>'
                .'<p><a href=":url">View the mutual aid</a></p>'
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

        // TASK-1775 (CDC C3) — ultimate fallback for "a help request was
        // published". Same reasoning as the entries above.
        'loop_help_request_created' => [
            'subject' => 'A help request was published in a Loop',
            'body' => '<p>Hello,</p>'
                .'<p>A help request was published in a Loop you are a member of.</p>'
                .'<p><a href=":url">Open the Loop</a></p>'
                .'<p>If the link does not work, copy this address into your browser:<br>:url</p>'
                .'<p>See you soon,<br>The BouclePro team</p>',
        ],

        // TASK-1802 — fallback. The real render goes through the
        // administrable `loop_event_created` template (seeder + migration);
        // this only serves when it is missing or disabled. `:url`,
        // `:reglages`, `:titre`, `:date`, `:boucle` and `:infos` stay
        // TOKENS — a hardcoded address here would escape the deliverer's
        // redaction (guard TASK1379…::test_every_email_body_uses_the_url_placeholder).
        'loop_event_created' => [
            'subject' => ':titre — :date',
            'body' => '<p>Hello,</p>'
                .'<p>A new event has been proposed in the <strong>:boucle</strong> Loop: <strong>:titre</strong>, on :date.</p>'
                .'<p>:infos</p>'
                .'<p>:description</p>'
                .'<p><a href=":url">Open the Loop</a></p>'
                .'<p>If the link does not work, copy this address into your browser:<br>:url</p>'
                .'<p>See you soon,<br>The BouclePro team</p>'
                .'<hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">To stop receiving this email, go to <a href=":reglages">Notification settings</a>.</p>',
        ],
    ],

    // TASK-1802 — the three meeting shapes, composed by `LoopEventMailer`
    // SERVER SIDE, never inside the template (administrable emails carry no
    // conditional).
    'loop_event_email_description_empty' => '(no description)',
    'loop_event_email_practical_online' => 'Online — meeting link: :url',
    'loop_event_email_practical_hybrid' => 'Hybrid — in person: :location, or online: :url',
    'loop_event_email_practical_in_person' => 'In person: :location',
];
