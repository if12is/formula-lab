<?php

return [
    'trade_material_status' => [
        'draft' => 'مسودة',
        'active' => 'نشط',
    ],
    'audit_action' => [
        'create' => 'إنشاء',
        'update' => 'تعديل',
        'delete' => 'حذف',
    ],
    'confidence' => [
        'L1_coa_batch' => 'شهادة تحليل الدفعة (COA)',
        'L2_supplier_tds' => 'نشرة بيانات المورد (TDS)',
        'L3_standard' => 'مواصفة قياسية',
        'L4_regulatory_db' => 'قاعدة بيانات تنظيمية',
        'L5_reference' => 'مرجع علمي',
        'L6_community' => 'مصدر مجتمعي',
        'L7_estimate' => 'تقدير',
    ],
    'formula_status' => [
        'draft' => 'مسودة',
        'lab_testing' => 'قيد التجربة في المعمل',
        'approved' => 'معتمدة',
        'rejected' => 'مرفوضة',
    ],
    'product_tier' => [
        'economy' => 'اقتصادي',
        'mid' => 'متوسط',
        'premium' => 'فاخر',
    ],
    'filter_type' => [
        'select' => 'اختيار واحد',
        'multiselect' => 'اختيار متعدد',
        'boolean' => 'نعم أو لا',
        'date-range' => 'نطاق تاريخ',
        'number-range' => 'نطاق أرقام',
    ],
];
