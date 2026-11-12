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
    // TASK-1806 — the last marketplace word left in a conversation title,
    // and it reached email subjects as a last-resort fallback.
    'exchange_subject' => 'Mutual aid #:id',

    // TASK-1754 §3.2 — arbitrage Q2 de Cyril (05/11) : le BADGE reste court et
    // NEUTRE, c'est la PHRASE qui porte le sens (qui aide qui).
    // TASK-1806 — these six labels also travel in the subject of status
    // emails (`{{ status_label }}`). "To confirm" named a step that no longer
    // exists for members: the visible journey has three, and the confirmation
    // belongs to the helped person. `buyer_done` is now a LEGACY status that
    // no code produces any more.
    'status' => [
        'pending' => 'Proposal',
        'accepted' => 'Help accepted',
        'buyer_done' => 'Help under way',
        'completed' => 'Help completed',
        'refused' => 'Declined',
        'cancelled' => 'Cancelled',
    ],

    'phrase' => [
        'pending_decideur' => ':prenom is answering “:titre” — :temps',
        'pending_proposant' => 'Your offer has been sent. :prenom needs to reply.',
        // TASK-1806 — once agreed, each side must know what is expected of
        // THEM: the helped person confirms, the helper waits for it.
        'accepted_aide' => 'Mutual aid agreed with :prenom — :temps. Once it is done, confirm to close it.',
        'accepted_aidant' => 'Mutual aid agreed with :prenom — :temps. :prenom will confirm once it is done.',
        // LEGACY: no code produces `buyer_done` any more.
        'buyer_done_aidant' => ':prenom needs to confirm to close this mutual aid.',
        'buyer_done_aide' => ':prenom is waiting for your confirmation to close this mutual aid — :temps.',
        'completed' => 'Mutual aid completed — :temps transferred. Thank you!',
        'refused_proposant' => ':prenom declined your offer.',
        'refused_decideur' => 'You declined this offer.',
        'cancelled' => 'This mutual aid was cancelled.',
    ],

    'actions' => [
        'accept' => 'Accept the mutual aid',
        'refuse' => 'Decline',
        'adjust' => 'Suggest a different time',
        'cancel' => 'Cancel',
        'cancel_withdraw' => 'Withdraw my offer',
        'cancel_exchange' => 'Cancel the mutual aid',
        // TASK-1806 — now the label of the CLOSING button, in the bottom-of-
        // thread card that also carries the review. The only gesture of step 3.
        'mark_done' => 'I received the help',
        // LEGACY: the helper's confirmation left the visible journey.
        'confirm' => 'Confirm — receive my :temps',
        'contest' => 'Not finished yet',
    ],

    // TASK-1754 §9.4 — arbitrage Q6 : la note reste OBLIGATOIRE (colonne NOT
    // NULL qui nourrit la moyenne du profil), donc discrète et non optionnelle.
    // TASK-1754 §3.4 — les faits du fil, desormais traduits.
    'systeme' => [
        'proposition_envoyee' => '🤝 Offer sent — :temps',
        // TASK-1806 — KEYS never change: they are stored in the database
        // (`messages.metadata->system_key`) and renaming them would break the
        // rendering of the whole history. Only the VALUES change.
        'echange_accepte' => '✅ Help accepted. The mutual aid is under way.',
        'echange_decline' => 'Offer declined.',
        'temps_ajuste' => 'Time adjusted: :temps',
        'echange_annule' => 'Mutual aid cancelled.',
        // LEGACY: no longer written — the helped person confirms and closes
        // in a single gesture.
        'aide_recue' => ':prenom confirmed receiving the help.',
        'echange_termine' => '🎉 Mutual aid completed — :temps shared.',
        'pas_encore_termine' => 'The help is not finished yet: the mutual aid is active again.',
    ],
    // TASK-1755 §5.2 — le SENS de l'aide, sur chaque ligne de la liste.
    'sens' => [
        'vous_aidez' => 'You are helping :prenom · :temps',
        'on_vous_demande' => ':prenom is asking for your help · :temps',
        // TASK-1808 — the two cases of the HELPED person, which were missing:
        // they inherited the helper's sentence and read the exact opposite.
        'vous_demandez' => 'You are asking :prenom for help · :temps',
        'on_vous_aide' => ':prenom is helping you · :temps',
        'terminee' => 'Mutual aid completed · :temps shared',
    ],
    // TASK-1806 (product arbitration, 11/11) — THREE visible steps, not five.
    'etapes' => [
        'demande' => 'Request',
        'accord' => 'Agreement',
        'confirmation' => 'Confirmation',
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
    // TASK-1806 — step 3: confirm, and say thank you in the same gesture.
    'confirmer_titre' => 'Did you receive :prenom’s help?',
    'confirmer_intro' => 'Confirming closes the mutual aid and transfers :temps to :prenom.',
    'confirmer_intro_offert' => 'Confirming closes the mutual aid. :prenom offered their help: no points are transferred.',
    'confirmer_note_label' => 'Leave a rating and a thank-you note (optional)',
    'confirmer_sans_note' => 'You can confirm without leaving a review.',
    // TASK-1808 §2/§3 — THE CLOSURE: one compact button, then a panel.
    'cloturer_bouton' => 'Mark the mutual aid as completed',
    'cloturer_titre' => 'Confirm the end of the mutual aid',
    'cloturer_intro' => 'This closes the mutual aid and transfers :temps to :prenom. The conversation will become read-only.',
    'cloturer_intro_offert' => 'This closes the mutual aid. :prenom offered their help: no points are transferred.',
    'cloturer_valider' => 'Confirm',
    'en_cours_titre' => 'Mutual aid under way',
    'en_cours_intro' => 'Chat with :prenom. Once the help has been received, confirm it here.',
    // TASK-1808 §3 — what each side reads AFTER the closure.
    'cloture_aide_titre' => 'Mutual aid completed!',
    'cloture_aide_corps' => ':temps were transferred to :prenom.',
    'cloture_aide_corps_offert' => ':prenom offered their help: no points were transferred.',
    'cloture_aidant_titre' => 'Thank you for your help!',
    'cloture_aidant_corps' => 'You received :temps from :prenom.',
    'cloture_aidant_corps_offert' => 'You offered your help to :prenom.',
    // TASK-1809 (Cyril's fix, 12/11) — the helper sees their rating IN the
    // thread, no need for the email to discover it. Replaces the generic
    // message above once a review exists.
    'cloture_aidant_evalue_titre' => ':prenom just left you a review',
    // TASK-1809 §2 — ONE compact card (rating + optional comment in a
    // single submission), replaces the two T1808 §5 micro-screens.
    'etoiles_titre' => 'How do you rate :prenom’s help?',
    'etoiles_valider' => 'Submit my review',
    // TASK-1809 (Cyril's fix, 12/11) — replaces the card once the review is
    // submitted; sharing in the Feed is now AUTOMATIC (no checkbox, no
    // separate link).
    'evaluation_effectuee_titre' => 'Review submitted',
    'evaluation_effectuee_lien' => 'View in the Feed',
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
    // TASK-1806 — the GROUP name stays (read by
    // `emails/transaction_status.blade.php`); only the values leave the
    // marketplace vocabulary.
    'email_exchange' => [
        'title' => 'Your mutual aid has been updated',
        'greeting' => 'Hello :prenom,',
        'intro' => 'Your mutual aid status has changed: **:statut**.',
        'label_exchange' => 'Mutual aid',
        'label_time' => 'Time shared',
        'cta' => 'View the conversation',
        'untitled' => 'Mutual aid',
        'subject' => 'Your mutual aid has been updated — :statut',
    ],

    // TASK-1772 — the directory preview opened by the detached avatar.
    'member_preview_open' => 'See :name’s profile card',
    'member_preview_close' => 'Close',
    'member_preview_title' => 'Directory card',
    'member_preview_full' => 'See full profile',
    'member_preview_write' => 'Send a private message',
    'member_preview_empty' => 'This person has not filled in their card yet.',
];
