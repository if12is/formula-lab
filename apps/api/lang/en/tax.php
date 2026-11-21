<?php

/*
| Tax per product (owner decision 2026-11-21): disabled (no tax line) or a rate from config/tax.php.
| No rate is written in this file: rates come from the configuration and from the dated, sourced tax regime.
*/
return [
    'title' => 'Tax',
    'description' => 'The tax setting of this product. It is saved on the product itself and feeds the price waterfall and what is published to Sanad.',
    'setting' => 'Tax setting',
    'setting_hint' => 'Disabled means there is no tax line in the price. A 0% rate shows a tax line whose value is zero.',
    'select_placeholder' => 'Choose the tax setting',
    'save' => 'Save tax setting',
    'saved' => 'The tax setting of the product was saved.',
    'options' => [
        'disabled' => 'Tax disabled (no tax line)',
        'rate' => 'Tax at :rate%',
    ],
    'state' => [
        'label' => 'Tax state',
        'not_set' => 'Not set',
        'disabled' => 'Disabled',
        'applied' => 'Enabled at',
    ],
    'notes' => [
        'not_set' => 'The tax of this product is not set yet, so the selling price is incomplete and nothing is published to Sanad until you choose it.',
        'disabled' => 'Tax is disabled on this product: there is no tax line in the price, and the final price is the price before tax.',
        'zero' => 'A 0% rate: the tax line is shown with a value of zero and the final price equals the price before tax.',
    ],
    'regime' => [
        'title' => 'Tax regime in force',
        'rate' => 'Rate',
        'valid_from' => 'Valid from',
        'valid_to' => 'Valid until',
        'open_ended' => 'Open ended',
        'source' => 'Source',
        'confidence' => 'Confidence',
        'verification' => 'Verification',
        'as_of' => 'On the price date',
        'none_in_force' => 'No tax regime of this rate is in force on the price date.',
        'verification_status' => [
            'pending' => 'Waiting for the legal source to be checked',
            'verified' => 'Verified',
        ],
        'pending_alert' => 'The legal source of this rate has not been attached yet. The value here is the owner\'s decision and stays "pending verification" until the source is added.',
    ],
    'preview' => [
        'title' => 'Preview the tax on a price',
        'price' => 'Price before tax',
        'tax_amount' => 'Tax amount',
        'price_with_tax' => 'Price with tax',
        'final_price' => 'Final price',
        'no_tax_line' => 'No tax line',
        'hint' => 'Type a price to see the tax line the way the engine computes it.',
        'egp' => 'EGP',
    ],
    'sanad' => [
        'note' => 'When published to Sanad: disabled tax is sent with no rate, 0% is sent as "0.00", and any other rate as itself.',
    ],
];
