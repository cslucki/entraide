<?php

return [
    'title' => 'Messages',

    // TASK-1703 — le sous-titre de la barre haute de la messagerie.
    'conversation_count' => '{0}No conversation|{1}1 conversation|[2,*]:count conversations',
    'no_conversations' => 'No conversations',
    'back_to_conversations' => 'Back to conversations',
    'select_conversation' => 'Select a conversation',
    'direct_conversation' => 'Discussion',
    'direct_conversation_started' => 'Discussion started.',
    'exchange_subject' => 'Exchange #:id',

    // TASK-1754 §3.2 — arbitrage Q2 de Cyril (05/11) : le BADGE reste court et
    // NEUTRE, c'est la PHRASE qui porte le sens (qui aide qui).
    'status' => [
        'pending' => 'Proposal',
        'accepted' => 'Agreed',
        'buyer_done' => 'To confirm',
        'completed' => 'Done',
        'refused' => 'Declined',
        'cancelled' => 'Cancelled',
    ],

    'phrase' => [
        'pending_decideur' => ':prenom is answering your listing “:titre” — :temps',
        'pending_proposant' => 'Your offer has been sent. :prenom needs to reply.',
        'accepted' => 'You agreed on this exchange — :temps',
        'buyer_done_aidant' => ':prenom says they received your help. Confirm to receive your :temps.',
        'buyer_done_aide' => 'You said you received the help. :prenom needs to confirm.',
        'completed' => 'Exchange complete — :temps transferred. Thank you!',
        'refused_proposant' => ':prenom declined your offer.',
        'refused_decideur' => 'You declined this offer.',
        'cancelled' => 'This exchange was cancelled.',
    ],

    'actions' => [
        'accept' => 'Accept the exchange',
        'refuse' => 'Decline',
        'adjust' => 'Suggest a different time',
        'cancel' => 'Cancel',
        'cancel_withdraw' => 'Withdraw my offer',
        'cancel_exchange' => 'Cancel the exchange',
        'mark_done' => 'I received the help',
        'confirm' => 'Confirm — receive my :temps',
        'contest' => 'Not finished yet',
    ],

    // TASK-1754 §9.4 — arbitrage Q6 : la note reste OBLIGATOIRE (colonne NOT
    // NULL qui nourrit la moyenne du profil), donc discrète et non optionnelle.
    // TASK-1754 §3.4 — les faits du fil, desormais traduits.
    'systeme' => [
        'proposition_envoyee' => '🤝 Offer sent — :temps',
        'echange_accepte' => '✅ Exchange accepted. The help is under way.',
        'echange_decline' => 'Offer declined.',
        'temps_ajuste' => 'Time adjusted: :temps',
        'echange_annule' => 'Exchange cancelled.',
        'aide_recue' => ':prenom confirmed receiving the help. Awaiting your confirmation.',
        'echange_termine' => '🎉 Exchange complete — :temps shared.',
        'pas_encore_termine' => 'The help is not finished yet: the exchange is active again.',
    ],
    // TASK-1755 §5.2 — le SENS de l'aide, sur chaque ligne de la liste.
    'sens' => [
        'vous_aidez' => 'You are helping :prenom · :temps',
        'on_vous_demande' => ':prenom is asking for your help · :temps',
        'terminee' => 'Exchange complete · :temps shared',
    ],
    'etapes' => [
        'proposition' => 'Offer',
        'accord' => 'Agreement',
        'aide' => 'Help',
        'confirmation' => 'Confirmation',
        'evaluation' => 'Evaluation',
    ],
    'filtres' => [
        'tous' => 'All',
        'en_cours' => 'Active',
        'discussions' => 'Discussions',
        'termines' => 'Completed',
    ],
    'autres_actions' => 'More actions',
    'review_exchange' => 'Say thank you to :prenom',
    // TASK-1806 — no longer repeats "the exchange is complete, :temps
    // shared": the Bento recap card already says it right above.
    'review_intro' => 'Your thank-you note will appear on their profile.',
    'review_placeholder' => 'A word of thanks (shown on their profile)…',
    'review_rating_hint' => 'Choose a rating.',
    'review_rating_required' => 'Choose a rating before sending.',
    'send_review' => 'Send the thank-you',
    'review_sent' => 'Thank you for your review!',
    'review_skip' => 'Skip for now',
    'bento_title' => 'Help completed',
    'bento_duree' => 'Duration',
    'bento_duree_absente' => 'Duration not available',
    'bento_points' => 'Points awarded',
    'bento_points_value' => '{1}:n pt|[2,*]:n pts',
    'bento_aidant' => 'Helper',
    'bento_aide' => 'Helped',
    'bento_contribution_title' => 'You helped :prenom',
    'bento_contribution_body' => 'Thank you for your contribution.',
    'composer_placeholder' => 'Your message...',
    // TASK-1772 — the private messaging composer now uses the ChatLoop pill
    // and its « + » sheet. Labels live here, not in `loops.php`.
    'composer_more_actions' => 'More actions',
    'composer_add_image' => 'Add an image',
    'composer_add_file' => 'Add a file',
    'composer_take_photo' => 'Take a photo',
    'conversation_closed' => 'This conversation is closed.',
    'empty_thread' => 'No messages yet',
    'empty_thread_hint' => 'Be the first to write!',
    'write_message' => 'Write a message...',
    'reply_to' => 'Reply to',
    'image_preview' => 'Preview',
    'image_alt' => 'Image',
    'reply' => 'Reply',
    // TASK-1770 — nom accessible de la feuille d'actions (appui long).
    'message_actions' => 'Message actions',
    // TASK-1771 — le mode selection (barre d'actions en haut).
    'selection_exit' => 'Exit selection',
    'selection_one' => '1 message selected',
    'pin' => 'Pin',
    'unpin' => 'Unpin',
    'edit' => 'Edit',
    'save' => 'Save',
    'cancel_edit' => 'Cancel',
    'delete' => 'Delete',
    'delete_confirm' => 'Delete this message?',
    'delete_modal_title' => 'Delete this message?',
    'delete_modal_body' => 'The message will be hidden in the loop and replaced with a deletion notice.',
    'copy' => 'Copy',
    'copied' => 'Copied',
    'copy_failed' => 'Unable to copy',
    'edited' => 'edited',
    'deleted_message_placeholder' => 'This message has been deleted.',
    'load_previous_messages' => 'Load previous messages',
    'edit_failed' => 'Unable to edit this message.',
    'react_to_message' => 'React to message',
    'react_with' => 'React with :type',
    'more_reactions' => 'Show more reactions',
    'pinned_message' => 'Pinned message',
    'view' => 'View',
    'me' => 'Me',
    'member' => 'Member',
    'body_or_image_required' => 'Add a message or an image.',
    'invalid_file' => 'Only images (JPG, PNG, GIF, WebP) are accepted.',
    'cross_org' => 'You cannot start a conversation outside your organization.',
    // TASK-1693 bug AC
    'send_image' => 'Send an image',
    // TASK-1704 — see the French file for why both labels coexist.
    'send_file' => 'Send an image or a file',
    'invalid_attachment' => 'This file type is not accepted, or it exceeds the maximum size.',
    'attachment_fallback_name' => 'Attachment',
    'attachment_open' => 'Open',
    'attachment_download' => 'Download',
    'take_photo' => 'Take a photo',

    /*
     * TASK-1760 — le REPLI de l'email de statut (`emails/transaction_status`).
     * Le gabarit administrable en base reste le chemin normal ; ces libelles
     * servent le jour ou il est desactive. La PHRASE de statut, elle, vient
     * de `CarteEntraide::phrase()` : une seule definition, orientee role.
     */
    'email_exchange' => [
        'title' => 'Your exchange has been updated',
        'greeting' => 'Hello :prenom,',
        'intro' => 'Your exchange status has changed: **:statut**.',
        'label_exchange' => 'Exchange',
        'label_time' => 'Time shared',
        'cta' => 'View the conversation',
        'untitled' => 'Exchange',
        'subject' => 'Your exchange has been updated — :statut',
    ],

    // TASK-1772 — the directory preview opened by the detached avatar.
    'member_preview_open' => 'See :name’s profile card',
    'member_preview_close' => 'Close',
    'member_preview_title' => 'Directory card',
    'member_preview_full' => 'See full profile',
    'member_preview_write' => 'Send a private message',
    'member_preview_empty' => 'This person has not filled in their card yet.',
];
