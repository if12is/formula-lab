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
    'sanad_entity' => [
        'product' => 'Product',
        'supplier' => 'Supplier',
        'purchase' => 'Purchase',
        'batch' => 'Batch',
        'stock_movement' => 'Stock movement',
    ],
    'sanad_op' => [
        'upsert' => 'Create or update',
        'delete' => 'Delete',
    ],
    'sanad_ref_type' => [
        'product' => 'Product',
        'supplier' => 'Supplier',
        'purchase' => 'Purchase',
        'purchase_item' => 'Purchase line',
        'batch' => 'Batch',
    ],
    'sanad_item_kind' => [
        'merchandise' => 'Merchandise',
        'raw_material' => 'Raw material',
        'packaging' => 'Packaging',
        'finished_good' => 'Finished good',
    ],
    'sanad_outbox_kind' => [
        'production_order' => 'Production order',
        'product_upsert' => 'Product upsert',
        'wholesale_prices' => 'Wholesale prices',
    ],
    'sanad_outbox_status' => [
        'pending' => 'Pending',
        'applied' => 'Applied',
        'failed' => 'Failed',
        'conflict' => 'Conflict',
    ],
    'sanad_conflict_reason' => [
        'formula_lab_newer' => 'Formula Lab has a newer version',
        'invalid_reference' => 'Invalid reference',
        'schema_mismatch' => 'Schema mismatch',
    ],
    'sanad_ability' => [
        'changes:write' => 'Receive changes from Sanad',
        'outbox:read' => 'Let Sanad read the outbox',
        'outbox:ack' => 'Let Sanad acknowledge outbox items',
    ],
    'sanad_change_status' => [
        'applied' => 'Applied',
        'unchanged' => 'Unchanged',
        'conflict' => 'Conflict',
    ],
];
