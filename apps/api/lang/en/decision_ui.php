<?php

/* Wording of the shared decision card and the override dialog (Y-04, Y-08). Shared with the interface, so {name} placeholders. */
return [
    'override' => [
        'button' => 'Override this decision',
        'title' => 'Override a blocked decision',
        'description' => 'Going ahead although a rule fails is recorded for good: who you are, your role, your reason and the time. The failed rules stay in the record.',
        'reason' => 'Written reason',
        'reason_hint' => 'Why is it right to go ahead? This is kept with your signature and cannot be edited later.',
        'password' => 'Your password',
        'password_hint' => 'Signing needs your password again.',
        'confirm' => 'Sign and override',
        'recorded' => 'The override is signed and recorded. The decision can now go ahead.',
        'why_not_signed_in' => 'Overriding needs a signed-in user, and sign-in is not available yet.',
        'why_role' => 'Your role is not allowed to override a blocked decision.',
        'active_title' => 'Overridden',
        'active_description' => 'This blocked decision was overridden and signed. The failed rules above still stand in the record.',
        'by' => 'Signed by',
        'role' => 'Role',
        'at' => 'Signed on',
        'reason_label' => 'Reason',
        'unknown_user' => 'Unknown user',
    ],
    'card' => [
        'allowed' => 'All the rules pass.',
        'blocked' => 'Blocked: these conditions fail.',
        'undecidable' => 'The rules could not be judged: {detail}',
        'failed_count' => '{count} failed',
        'step' => 'Required step',
        'rule_set' => 'Rules {id}, version {version}',
        'hash' => 'Decision hash',
        'passed' => 'Conditions that pass',
    ],
];
