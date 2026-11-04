<?php

return [
    // TASK-1752 — automatic events. No label says "just now": the same line
    // read a week later would be false, and it already carries its date.
    'events' => [
        'badge' => 'Activity',
        'presentation_completed' => ':name completed their presentation',
        'help_request_published' => ':name published a request for help',
        'service_published' => ':name published an offer of help',
        'loop_joined' => ':name joined the :object Loop',
        'loop_created' => ':name created the :object Loop',
        'loop_event_created' => ':name created the event :object',
        'object_gone' => 'a deleted item',
    ],

    'organization' => 'Organization',
    'title' => 'Feed',
    'subtitle' => 'Useful announcements, decisions and key information to keep in mind.',
    'dashboard' => 'My dashboard',
    'my_posts' => 'My announcements',
    'new_post' => 'New announcement',
    'pinned' => 'Pinned',
    'empty' => 'No announcements yet.',
    'create_first' => 'Create the first announcement',
    'load_more' => 'Show more',
    'post_badge' => 'Announcement',
    'pinned_badge' => 'Pinned',
    'loop_count' => ':count loop|:count loops',
    'member_fallback' => 'Member',
    'shared_link' => 'Shared link',
    'comment_cta' => 'Comment',
    'view_comments' => 'View :count comments',
    'comment_placeholder' => 'Add a useful comment',
    'comment_submit' => 'Comment',
    'react' => 'React :type',
    'comments_title' => 'Comments (:count)',
];
