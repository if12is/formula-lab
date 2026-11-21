<?php

/*
| Texts for the message keys the calculation engine (packages/engine) returns in `missing[]`, `warnings[]` and the
| per-line issues of the formula checks. The engine only emits keys; this file is their wording.
| Warnings carry details after a colon, so the two that name a material take placeholders.
*/
return [
    'formula' => [
        'empty' => 'The formula has no lines.',
        'sum_not_100' => 'The percentages do not add up to 100.',
        'percent_not_positive' => 'The percentage must be above zero.',
        'percent_invalid' => 'Enter the percentage as a number, such as 12.5.',
        'duplicate_material' => 'This material appears on more than one line.',
        'qs_missing' => 'Choose which material balances the formula (q.s.).',
        'qs_multiple' => 'Only one material can balance the formula (q.s.).',
        'qs_balance_not_positive' => 'The other lines already add up to 100 or more, so nothing is left for the q.s. line.',
        'scaled_sum_not_exact' => 'The scaled amounts do not add up exactly.',
    ],
    'cost' => [
        'price_missing' => 'No price is recorded for this material.',
        'price_not_money' => 'The recorded price is not an amount of money.',
        'price_negative' => 'The recorded price is negative.',
        'price_not_dated' => 'The recorded price has no valid date.',
        'fx_rate_required' => 'The price is in another currency and no dated exchange rate is available.',
        'margin_invalid' => 'The margin is not a valid number.',
        'margin_must_be_non_negative' => 'The margin cannot be negative.',
        'margin_must_be_below_100' => 'The margin must be below 100%.',
    ],
    'confidence' => [
        'l7_requires_signed_override' => 'Only an estimated price exists, so it is not used. Add a dated, sourced price.',
        'l7_override' => 'The estimated price of {material} is used under a signed exception.',
        'below_policy' => 'The price of {material} rests on a weak source ({confidence}).',
    ],
    'input' => [
        'missing' => 'A required value is missing.',
        'invalid_number' => 'The value is not a valid number.',
        'unit_mismatch' => 'The value is in the wrong unit.',
        'not_dated' => 'The value has no valid date.',
        'out_of_range' => 'The value is outside the allowed range.',
        'invalid_mass' => 'The mass must be a positive weight.',
        'invalid_volume' => 'The volume must be a positive amount.',
    ],
    'density' => [
        'must_be_positive_density_unit' => 'The density must be positive and in a density unit.',
        'required_for_conversion' => 'A density is needed to convert between weight and volume.',
    ],
    'batch' => [
        'wastage_invalid' => 'The wastage is not a valid number.',
        'wastage_out_of_range' => 'The wastage must be between 0 and 100%.',
        'unit_must_be_mass_or_volume' => 'The batch size must be a weight or a volume.',
        'size_not_positive' => 'The batch size must be above zero.',
    ],
    'engine' => [
        'version_mismatch' => 'The calculation engine version differs from the one this request names.',
    ],
];
