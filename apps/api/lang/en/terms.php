<?php

/*
| Terms of use and disclaimer (S-16). THE WORDING BELOW IS A PROJECT DRAFT, not legal text: it only states how the platform works.
| The owner or their lawyer supplies the final wording (HUMAN-TODO S-16). Change the wording and bump config/terms.php `version`
| (and set `is_draft` to false once it is final); everyone is then asked to accept again.
*/
return [
    'page_title' => 'Terms of use',
    'title' => 'Terms of use and disclaimer',
    'description' => 'Read these terms and accept them to continue.',
    'gate_notice' => 'You need to accept the current terms of use before you carry on.',
    'draft_title' => 'Draft wording',
    'draft_description' => 'This text is a draft written by the project team to state how the platform works. It is not final legal wording: the owner or their lawyer supplies that.',
    'version' => 'Version',
    'hash' => 'Wording fingerprint',
    'hash_hint' => 'A fingerprint of this exact wording is stored with your acceptance.',
    'agreement' => 'Agreement',
    'accept_label' => 'I have read the terms of use and the disclaimer and I accept them',
    'accept' => 'Accept and continue',
    'accepted_state' => 'Accepted',
    'accepted_on' => 'You accepted version :version on :date.',
    'not_accepted_state' => 'Not accepted yet',
    'accepted_flash' => 'Terms accepted. Thank you.',
    'must_accept' => 'Tick the box to accept the terms before you continue.',
    'required_json' => 'The terms of use must be accepted before this can be used.',
    'back' => 'Back to where you were',
    'open_link' => 'Terms of use',
    'sections' => [
        'purpose' => [
            'title' => 'What this platform is',
            'body' => 'Formula Lab helps formulators work out formulas, costs, price ladders and compliance checks. Every figure it shows comes from a deterministic calculation on recorded values, each with its source and confidence level.',
        ],
        'lab_trial' => [
            'title' => 'Stability and safety are confirmed by lab trial',
            'body' => 'A calculation, a compliance check or a template does not prove that a formula is stable or safe. Stability, safety, preservation and performance are established only by lab trials on the real batch, recorded in the Lab and approved by the responsible person. Nothing in this platform replaces a trial.',
        ],
        'assistant' => [
            'title' => 'The assistant never calculates',
            'body' => 'The AI assistant explains and calls the platform\'s calculation engine. It does not compute, estimate or invent numbers. When a value is missing the platform says what is missing instead of guessing.',
        ],
        'sources' => [
            'title' => 'Regulatory and reference data',
            'body' => 'Limits, rules and reference values shown (for example from ES, CosIng or IFRA) are recorded with a source and a confidence level. Check the official text before relying on one. A value that has no source is left empty, never filled by estimate.',
        ],
        'legal_pending' => [
            'title' => 'Liability, data protection and governing law',
            'body' => '[DRAFT PLACEHOLDER] The final wording on responsibility, data protection and governing law has not been supplied yet. The owner or their lawyer adds it here, and everyone is then asked to accept again.',
        ],
    ],
];
