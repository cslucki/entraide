<?php

return [
    'notification' => [
        'created' => 'Request published successfully.',
        'created_and_relayed' => 'Request published and announced in the Loop.',
        'updated' => 'Request updated.',
        'closed' => 'Request closed.',
    ],

    'relay_loop_label' => 'Relay in a Loop',
    'relay_loop_none' => 'Do not relay this request',
    'relay_loop_help' => 'The announcement is created only after the request is published.',
    'relay_loop_invalid' => 'This Loop is not an authorized relay destination.',
    'relay_failed' => 'The request was created, but its Loop announcement could not be published.',
    'chat_projection_body' => 'New help request: :title',
    'view_request' => 'View request',
    'projection_unavailable' => 'This request is no longer accessible.',
    'status_closed' => 'Request closed',

    'show' => [
        'back' => 'Back to explorer',
        'edit' => 'Edit',
        'points' => 'points',
        'attachments' => 'Attachments',
        'report_button' => 'Report this request',
        'report_placeholder' => 'Reason for the report...',
        'report_inappropriate' => 'Inappropriate content',
        'report_scam' => 'Scam or fraud',
        'report_spam' => 'Spam',
        'report_other' => 'Other',
        'report_details' => 'Details (optional)...',
        'report_submit' => 'Send report',
        'propose_help' => 'Propose my help',
        'your_balance' => 'Your balance: :points pts',
        'before' => 'Before :date',
    ],

    'edit' => [
        'heading' => 'Help request',
        'description' => 'Description *',
        'category' => 'Category *',
        'delivery_mode' => 'Delivery mode *',
        'remote' => '🌐 Remote',
        'onsite' => '📍 On site',
        'both' => '🌐📍 Both',
        'budget_min' => 'Min budget *',
        'budget_max' => 'Max budget',
        'optional' => '(optional)',
        'attachments' => 'Attachments',
        'attachments_add' => 'Add files',
        'attachments_types' => 'JPG, PNG, PDF, DOC, XLS — max 10 MB',
        'deadline' => 'Desired date',
        'save' => 'Save',
        'cancel' => 'Cancel',
    ],

    // TASK-1553 — W2-2: the pre-send card. It promises NOTHING — see the French
    // file for the full rationale.
    'presend_title' => 'Before publishing',
    'presend_destination' => 'Will be published in:',
    'presend_members' => '{0} no members|{1} 1 member|[2,*] :count members',
    'presend_origin_shell' => 'Prepared from your conversation with BouclePro AI.',
    'presend_origin_loop_clarification' => 'Prepared from "Who can help me?" in this Loop.',
    'presend_origin_loop_clarification_unavailable' => 'Prepared from your own words, without AI involvement.',
    'presend_note' => 'Nothing is published until you confirm it. You can change everything below.',
];
