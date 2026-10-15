<?php

return [
    // TASK-1685 — the product's 403 page. There was none: Laravel rendered the
    // framework's bare « Forbidden ». These texts serve the GENERAL case; the
    // membership refusal (T1483) and the unshared Dossier (T1145) keep theirs.
    '403_title' => 'You do not have access to this page',
    '403_message' => 'Your account does not have the rights needed for this page.',
    '403_back' => 'Back',
    '404_title' => 'Page not found',
    '404_message' => 'Hmm, this loop seems to be on vacation…',
    // TASK-1685 (Q3 + MASTER UX) — the DEMONSTRATED cross-tenant refusal.
    //
    // Status stays 404 (T1601-T1606 doctrine) and T1606's view is reused: only
    // the PRESENTATION becomes that of an information page. No lookup of the
    // targeted Organization happens, so a nonexistent slug yields these SAME
    // texts: no existence oracle.
    '404_cross_tenant_title' => 'You do not have access to this space',
    '404_cross_tenant_body' => 'You are not a member of the organization this page belongs to.',
    '404_cross_tenant_hint' => 'If you think you should have access, ask its administrator for an invitation.',
    '404_cross_tenant_back' => 'Back to my organization',
    '404_back_home' => 'Back to home',
    // TASK-1483 — see the French file: the name only shows for a public
    // Organization, otherwise the neutral wording.
    'org_member_required_title' => 'Members only',
    'org_member_required_heading' => 'This page is reserved for members of :organization.',
    'org_member_required_heading_neutral' => 'This page is reserved for members of this organization.',
    'org_member_required_body' => 'You are signed in with an account that does not belong to this organization.',
    'org_member_required_own_space' => 'Back to my space',
    'org_member_required_public_home' => 'View this organization\'s home page',
];
