<?php

return [
    'trade_material_status' => [
        'draft' => 'Draft',
        'active' => 'Active',
    ],
    'audit_action' => [
        'create' => 'Create',
        'update' => 'Update',
        'delete' => 'Delete',
    ],
    'confidence' => [
        'L1_coa_batch' => 'Batch certificate of analysis (COA)',
        'L2_supplier_tds' => 'Supplier technical data sheet (TDS)',
        'L3_standard' => 'Standard specification',
        'L4_regulatory_db' => 'Regulatory database',
        'L5_reference' => 'Scientific reference',
        'L6_community' => 'Community source',
        'L7_estimate' => 'Estimate',
    ],
    'formula_status' => [
        'draft' => 'Draft',
        'lab_testing' => 'In lab testing',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ],
    'product_tier' => [
        'economy' => 'Economy',
        'mid' => 'Mid-range',
        'premium' => 'Premium',
    ],
    'filter_type' => [
        'select' => 'Single choice',
        'multiselect' => 'Multiple choice',
        'boolean' => 'Yes or no',
        'date-range' => 'Date range',
        'number-range' => 'Number range',
    ],
];
