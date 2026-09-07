<?php

return [
    // TASK-1414 — initial pipeline of an Organization, seeded ONCE in its
    // locale; from then on these are its own labels, renamed freely.
    'default_status' => [
        'new' => 'New',
        'to_contact' => 'To contact',
        'in_progress' => 'In progress',
        'quote_sent' => 'Quote sent',
        'client' => 'Client',
        'lost' => 'Lost',
    ],
    // TASK-1416 — CRM-4: the "Relationships" list.
    'title' => 'Relationships',
    'subtitle' => 'Your prospects, participants and clients: where things stand, and what comes next.',
    'new_contact' => 'New contact',
    'save_contact' => 'Save',
    'add_note' => 'Note',
    'save_note' => 'Add',
    'note_placeholder' => 'What was said, what was agreed…',
    'never_contacted' => 'Never',
    'empty' => 'No contact yet. Add your first prospect.',
    'linked_account' => 'Account',
    'do_not_contact' => 'Do not contact',
    'follow_member' => 'Add to follow-up',
    'field' => [
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'email' => 'Email',
        'phone' => 'Phone',
        'company' => 'Company',
        'email_or_phone_hint' => 'An email or a phone number is enough.',
    ],
    'filter' => [
        'search' => 'Search by name, email, phone, company…',
        'all_statuses' => 'All statuses',
        'idle' => 'No contact for :days days',
    ],
    'column' => [
        'name' => 'Name',
        'email' => 'Email',
        'phone' => 'Phone',
        'status' => 'Status',
        'last_interaction' => 'Last contact',
        'source' => 'Source',
        'actions' => 'Actions',
    ],
    'source' => [
        'manual' => 'Manual',
        'signup' => 'Sign-up',
        'workshop' => 'Workshop',
        'shell_welcome' => 'Shell',
        'import' => 'Import',
    ],
    'channel' => [
        'none' => 'Internal memo',
        'call' => 'Call',
        'meeting' => 'Meeting',
        'email' => 'Email',
        'whatsapp' => 'WhatsApp',
        'sms' => 'SMS',
        'other' => 'Other contact',
    ],
    'flash' => [
        'contact_created' => 'Contact added.',
        'contact_found' => 'This contact already existed: it was found, nothing was duplicated.',
        'status_changed' => 'Status updated: :status.',
        'note_added' => 'Note added.',
        'member_followed' => 'The member is now followed in Relationships.',
        'member_already_followed' => 'This member was already followed.',
        'follow_conflict' => 'Cannot add this member to follow-up: a contact with this email is already linked to another account.',
    ],
];
