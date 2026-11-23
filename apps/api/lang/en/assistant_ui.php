<?php

/* The words of the assistant panel (P-10): the consent prompt and the chat. The assistant's own prompt is in assistant.php (never sent to the browser). */
return [
    'panel' => [
        'open' => 'Ask Claude',
        'title' => 'Formulation assistant',
        'description' => 'It understands your question and asks the calculation engine. Every number in its reply comes from the engine, not from an estimate.',
        'close' => 'Close the assistant',
    ],
    'consent' => [
        'title' => 'Consent to send data to the assistant',
        'intro' => 'The assistant works through the Claude service. Your formulas, costs and supplier certificates are not sent to it unless you allow it, and the consent covers this session only and ends with it.',
        'always_sent' => 'What you type in the question box is always sent, so do not type data you do not want to send.',
        'legend' => 'Allow sending',
        'category_hint' => [
            'formula_composition' => 'The materials in a formula and their percentages, when the assistant calculates, checks or proposes a formula.',
            'formula_cost' => 'The cost of a formula and the material prices it was calculated from.',
            'supplier_certificate' => 'The certificate of analysis file itself, or the values read from it.',
        ],
        'allow' => 'Allow for this session',
        'pick_one' => 'Choose at least one category.',
        'allowed_title' => 'Sending is allowed in this session',
        'allowed_until' => 'For: {categories}. It ends by itself at {time}.',
        'revoke' => 'Withdraw consent',
        'revoked' => 'Consent withdrawn. Nothing will be sent again without a new consent.',
        'granted' => 'Your consent for this session is recorded.',
        'failed' => 'The consent could not be recorded. Try again.',
        'coa_needed' => 'Reading a certificate of analysis sends the file to the Claude service. Allow that for this session first.',
        'expired_count' => ':count expired consents closed.',
    ],
    'chat' => [
        'placeholder' => 'Ask about a formula cost, its compliance or a unit conversion...',
        'label' => 'Your question',
        'send' => 'Send',
        'sending' => 'Thinking...',
        'new' => 'New conversation',
        'empty_title' => 'Ask the assistant',
        'empty_text' => 'For example: what is the cost per kg of this formula? Or: is this formula compliant?',
        'you' => 'You',
        'assistant' => 'Assistant',
        'tools_used' => 'Tools used: {tools}',
        'fallback' => 'This reply is not guaranteed: a safe message was returned because its numbers did not come from the engine.',
        'failed' => 'The assistant did not answer. Try again in a moment.',
        'too_long' => 'The question is longer than allowed ({max} characters).',
    ],
];
