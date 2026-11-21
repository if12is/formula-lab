<?php

/*
| Wording of the decision rule sets (approve a formula version, release a batch, publish to Sanad): packages/engine/data/decisions/*.json.
| A rule only carries a message key and a remedy key, the text is here. A rule key decision.X is decisions.X here.
| Numbers (such as the price change threshold) come from configuration into the decision context and reach the text as parameters.
*/
return [
    'afv' => [
        'engine_validation_failed' => 'The engine validation of the formula did not pass.',
        'sum_not_100' => 'The percentages add up to :totalPct%, not 100%.',
        'engine_missing_data' => 'Data is missing in the calculation: :missingProperties.',
        'compliance_not_pass' => 'The compliance result is :overall, not a pass (unknown blocks approval).',
        'critical_confidence_below_l2' => 'A critical material has data below confidence level 2: :materialIds.',
        'lab_results_missing' => 'Required lab results are missing: :missingProperties.',
        'open_deviations' => 'There are open deviations: :deviationIds.',
    ],
    'rb' => [
        'formula_version_not_approved' => 'Formula version :formulaVersionId is :status, not approved.',
        'coa_missing' => 'The certificate of analysis is missing for lots: :lotIds.',
        'deviations_not_approved' => 'Deviations not approved yet: :deviationIds.',
        'lab_out_of_spec' => 'Lab results are out of specification: :properties.',
        'lab_not_evaluated' => 'Results or specification limits are not recorded, so they cannot be judged: :properties.',
    ],
    'ps' => [
        'cost_not_approved' => 'The cost has not been approved yet.',
        'price_waterfall_incomplete' => 'The price waterfall is incomplete: :missingProperties.',
        'threshold_not_configured' => 'The price change threshold for re-approval is not set in the configuration.',
        'price_change_needs_reapproval' => 'A material price moved by :maxChangePct%, above the :thresholdPct% limit, so the cost must be approved again (:materialIds).',
    ],
    'remedy' => [
        'fix_formula_validation' => 'Fix the formula errors the engine lists, then try again.',
        'adjust_formula_to_100' => 'Adjust the percentages until they add up to 100%.',
        'complete_missing_data' => 'Complete the missing data (each value with its source and confidence).',
        'resolve_compliance' => 'Resolve the compliance items that fail or are unknown.',
        'upgrade_material_confidence' => 'Raise the material data confidence with a certificate of analysis or a supplier data sheet.',
        'record_lab_results' => 'Record the required lab results.',
        'close_deviations' => 'Close the open deviations.',
        'approve_formula_version' => 'Approve the formula version first.',
        'attach_coa' => 'Attach the certificate of analysis for every material lot.',
        'approve_deviations' => 'Approve or close the deviations.',
        'review_out_of_spec' => 'Review the out-of-specification results.',
        'record_results_or_specs' => 'Record the missing results or specification limits.',
        'approve_cost' => 'Approve the cost.',
        'complete_price_waterfall' => 'Complete the price waterfall inputs (including the product tax setting).',
        'configure_price_change_threshold' => 'Set the price change threshold in the decisions configuration.',
        'reapprove_cost' => 'Approve the cost again after reviewing the material prices.',
    ],
    'dsl' => [
        'approval_required' => 'A published rule set needs who approved it and the approval date.',
        'approval_not_allowed' => 'Approval is recorded when publishing, so a draft carries none.',
    ],
];
