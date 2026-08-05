<?php

return [

    // ── List ────────────────────────────────────────────────────────────────
    'title' => 'Polls',
    'create' => 'Create a poll',
    'open_section' => 'Open',
    'closed_section' => 'Closed',
    'empty' => 'No poll yet.',
    'empty_hint' => 'Ask the members a question: they answer, and the result reads at a glance.',
    'by' => 'By :name',
    'participants' => ':count participant(s)',
    'status_open' => 'Open',
    'status_closed' => 'Closed',
    'type_single' => 'Single choice',
    'type_multiple' => 'Multiple choice',
    'unknown_voter' => 'Unknown member',
    'unknown_author' => 'Unknown author',

    // ── Create and edit ─────────────────────────────────────────────────────
    'form_title_create' => 'Ask a question',
    'form_title_edit' => 'Edit the poll',
    'question' => 'Question',
    'question_placeholder' => 'What are you asking?',
    'description' => 'Detail (optional)',
    'description_placeholder' => 'A line of context, if it helps.',
    'selection_type' => 'Answers',
    'options' => 'Possible answers',
    'option_placeholder' => 'Answer :number',
    'add_option' => 'Add an answer',
    'remove_option' => 'Remove this answer',
    'publish' => 'Publish',
    'save' => 'Save',
    'cancel' => 'Cancel',
    'edit' => 'Edit',
    'edit_locked' => 'This poll has answers: it can no longer be edited.',

    // ── Vote ────────────────────────────────────────────────────────────────
    'vote' => 'Vote',
    'change_vote' => 'Change my vote',
    'your_vote' => 'Your vote:',
    'voted_confirmation' => 'Vote recorded.',
    'results_after_vote' => 'Results appear once you have voted.',

    // ── Results ─────────────────────────────────────────────────────────────
    'results' => 'Results',
    'result_line' => ':votes vote(s) · :percentage%',
    'total_participants' => ':count person(s) have answered.',
    'detail_show' => 'See who answered what',
    'detail_hide' => 'Hide the detail',
    'detail_empty' => 'Nobody has answered yet.',

    // ── Close and delete ────────────────────────────────────────────────────
    'close' => 'Close',
    'close_confirm_title' => 'Close this poll?',
    'close_confirm_body' => 'Members will no longer be able to vote or change their vote. Results are kept.',
    'close_confirm_cta' => 'Close',
    'closed_notice' => 'Closed on :date',
    'delete' => 'Delete',
    'delete_confirm_title' => 'Delete this poll?',
    'delete_confirm_body' => 'Nobody has answered yet: nothing will be lost. This cannot be undone.',
    'delete_confirm_cta' => 'Delete',
    'deleted' => 'Poll deleted.',

    // ── Read-only ───────────────────────────────────────────────────────────
    'read_only' => 'This Loop is archived: polls are read-only.',

    // ── Errors ──────────────────────────────────────────────────────────────
    'error_not_allowed' => 'You are not allowed to do this.',
    'error_not_member' => 'You must be an active member of the Loop.',
    'error_question_required' => 'The question cannot be empty.',
    'error_selection_type' => 'Unknown answer mode.',
    'error_min_options' => 'At least two possible answers are needed.',
    'error_max_options' => 'Ten answers at most.',
    'error_duplicate_option' => 'Two answers are identical.',
    'error_closed' => 'This poll is closed.',
    'error_already_voted' => 'This poll already has answers: it can no longer be edited.',
    'error_no_choice' => 'Pick at least one answer.',
    'error_single_choice' => 'This poll accepts a single answer.',
    'error_unknown_option' => 'This answer does not belong to this poll.',
    'error_delete_voted' => 'This poll has answers: it gets closed, not deleted.',

    // ── ChatLoop ────────────────────────────────────────────────────────────
    'chat_created' => 'A new poll has been published: :question',
    'chat_closed' => 'The poll “:question” is closed.',
    'chat_open_card' => 'Open the polls',
];
