<?php

return [
    'title' => 'Separate EDA path for disinfectants',
    'description' => 'A product that claims disinfection, or is a disinfectant family, is licensed by the Egyptian Drug Authority on its own path. This notice is separate from the compliance rules above and does not change their result.',
    'claims_label' => 'Claims the product makes',
    'claims_hint' => 'Choose the claims the label or the marketing makes. Saved on the formula.',
    'claims_placeholder' => 'No claim',
    'claims' => [
        'disinfection' => 'Disinfection',
        'antibacterial' => 'Antibacterial',
    ],
    'save' => 'Save claims',
    'saved' => 'Claims saved on the formula.',
    'applies_title' => 'This product is on the separate EDA disinfectant path',
    'applies' => 'Disinfectant preparations are licensed by the Egyptian Drug Authority under its guide for licensing factories and lines of disinfectant preparations ({guide}, {edition} edition), apart from the usual route of this product family.',
    'review_title' => 'A claim needs a decision',
    'review' => 'The claim recorded ({claims}) is one the sources have not decided on. A person must decide whether it counts as a disinfection claim; until then the EDA path is not ruled out.',
    'none' => 'No disinfection claim is recorded for this product.',
    'caveat' => 'Formula Lab only alerts. It does not register, approve or judge the claim, and it does not state what the guide requires: read the guide itself.',
    'triggers' => 'Triggered by',
    'trigger_family_group' => 'the product family is a disinfectant ({group})',
    'trigger_claim' => 'the claim: {claim}',
    'source' => 'Source: the project research notes on the Egyptian regulations (an estimate, not the guide itself).',
];
