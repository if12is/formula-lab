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
    'soap' => [
        'no_oils' => 'Add at least one oil.',
        'sap_range_inconsistent' => 'The SAP range is inconsistent: the minimum is above the typical value or the maximum below it.',
        'sap_range_unavailable' => 'The effect of the SAP range is not shown because minimum and maximum are not complete for the oils: {detail}.',
    ],
    'water' => [
        'concentration_must_be_below_100' => 'The lye concentration must be below 100%, otherwise there is no water.',
    ],
    'neutralize' => [
        'theoretical_start_final_ph_measured' => 'The result is a theoretical starting point: the final pH is measured and adjusted.',
    ],
    'active' => [
        'no_lines' => 'Add at least one component.',
        'value_required' => 'Enter the active matter of this component with its source.',
        'non_active_declaration_required' => 'Enter 0 with a source for a component that carries no active matter; do not leave it empty.',
        'contributing_but_zero' => 'A component meant to be active has 0% active matter.',
        'declared_non_active_has_value' => 'A component declared inactive has more than 0% active matter.',
        'target_exceeds_raw' => 'The target is above the raw grade\'s active matter and cannot be reached by dilution.',
        'target_exceeds_current' => 'The target is above the current active matter and cannot be reached by adding water.',
        'current_active_zero' => 'The current active matter is 0, so adding water means nothing.',
    ],
    'alcohol' => [
        'basis_required' => 'Choose the basis of the strength: % by volume or % by weight.',
        'target_exceeds_source' => 'The target strength is above the stock alcohol\'s and cannot be reached by dilution.',
        'out_of_range' => 'The value is outside the OIML R 22 table.',
        'invalid_input' => 'The value is not a valid number.',
        'table_empty_or_not_loaded' => 'The OIML R 22 table is not loaded.',
    ],
    'fragrance' => [
        'percents_exceed_100' => 'Oil, fixative and added water add up to 100% or more, leaving nothing for the carrier.',
        'final_ethanol_indicator_only' => 'The final ethanol % by volume is an indicator only: the OIML table is for alcohol and water and the oil is not water.',
        'ifra_unknown' => 'The IFRA check is unknown: there is no valid limit from the fragrance certificate, and that is not a pass.',
    ],
    'percent_from_weight' => [
        'unit_must_be_mass_or_volume' => 'The unit must be a mass or a volume.',
        'amount_negative' => 'The amount cannot be negative.',
        'batch_not_positive' => 'The batch size must be greater than zero.',
        'exceeds_100' => 'The percentage is above 100%: check the amount and the batch size.',
    ],
    'hlb' => [
        'no_oil_phase_lines' => 'Add at least one oil component.',
        'invalid_mass' => 'The mass is not a valid number.',
        'missing_required_hlb' => 'Enter the required HLB of this component with its source.',
        'invalid_required_hlb' => 'The required HLB is not a valid number.',
        'confidence_L7_rejected_by_policy' => 'This HLB is only an estimate (L7) and the calculation does not use it. Enter a better-sourced value.',
        'oil_phase_total_mass_zero' => 'The oil phase masses add up to zero.',
        'invalid_input' => 'The value is not a valid number.',
        'must_be_positive_mass' => 'The mass must be positive.',
        'target_out_of_range' => 'The target must lie between the HLB of the low and the high emulsifier.',
        'griffin_out_of_range' => 'Mh must be between 0 and M, and M must be greater than 0.',
        'no_groups' => 'Add at least one group.',
        'missing_or_invalid_group_number' => 'Enter the Davies number of this group with its source.',
        'count_must_be_positive_integer' => 'The number of times the group occurs must be a positive whole number.',
    ],
    'unknown_reason' => 'Reason from the engine: {reason}',
];
