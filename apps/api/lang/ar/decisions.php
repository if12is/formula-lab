<?php

/*
| نصوص قواعد القرارات (اعتماد التركيبة وإطلاق الدفعة والنشر إلى سند): packages/engine/data/decisions/*.json.
| القاعدة تحمل مفتاح الرسالة ومفتاح الإجراء المقترح فقط، والنص هنا. مفتاح decision.X في القاعدة هو decisions.X هنا.
| الأرقام (مثل حد تغيّر السعر) تأتي من الإعدادات إلى سياق القرار فتظهر في النص كمعاملات.
*/
return [
    'afv' => [
        'engine_validation_failed' => 'التحقق الحسابي للتركيبة لم ينجح.',
        'sum_not_100' => 'مجموع النسب :totalPct٪ وليس 100٪.',
        'engine_missing_data' => 'بيانات ناقصة في الحساب: :missingProperties.',
        'compliance_not_pass' => 'نتيجة المطابقة :overall وليست نجاحاً (غير المعروف يمنع الاعتماد).',
        'critical_confidence_below_l2' => 'ثقة بيانات خامة حرجة أقل من المستوى الثاني: :materialIds.',
        'no_completed_trial' => 'مفيش تجربة معمل للنسخة دي اتكمّلت لسه.',
        'lab_results_missing' => 'نتائج معملية مطلوبة ناقصة: :missingProperties.',
        'open_deviations' => 'توجد انحرافات مفتوحة: :deviationIds.',
    ],
    'rb' => [
        'formula_version_not_approved' => 'نسخة التركيبة :formulaVersionId حالتها :status وليست معتمدة.',
        'coa_missing' => 'شهادة التحليل ناقصة للدفعات: :lotIds.',
        'deviations_not_approved' => 'انحرافات لم تُعتمد بعد: :deviationIds.',
        'lab_out_of_spec' => 'نتائج معملية خارج المواصفة: :properties.',
        'lab_not_evaluated' => 'نتائج أو حدود مواصفة غير مسجلة فلا يمكن تقييمها: :properties.',
    ],
    'ps' => [
        'cost_not_approved' => 'التكلفة لم تُعتمد بعد.',
        'price_waterfall_incomplete' => 'سلّم السعر غير مكتمل: :missingProperties.',
        'threshold_not_configured' => 'حد تغيّر السعر لإعادة الاعتماد غير مضبوط في الإعدادات.',
        'price_change_needs_reapproval' => 'تغيّر سعر خامة بنسبة :maxChangePct٪ وهو أعلى من الحد :thresholdPct٪ فتلزم إعادة اعتماد التكلفة (:materialIds).',
    ],
    'remedy' => [
        'fix_formula_validation' => 'صحّح أخطاء التركيبة التي يعرضها المحرك ثم أعد المحاولة.',
        'adjust_formula_to_100' => 'عدّل النسب حتى يصبح المجموع 100٪.',
        'complete_missing_data' => 'أكمل البيانات الناقصة (القيمة بمصدرها ودرجة ثقتها).',
        'resolve_compliance' => 'عالج بنود المطابقة غير الناجحة أو غير المعروفة.',
        'upgrade_material_confidence' => 'ارفع ثقة بيانات الخامة بشهادة تحليل أو ورقة بيانات المورد.',
        'record_lab_results' => 'سجّل النتائج المعملية المطلوبة.',
        'close_deviations' => 'أغلق الانحرافات المفتوحة.',
        'approve_formula_version' => 'اعتمد نسخة التركيبة أولاً.',
        'attach_coa' => 'أرفق شهادة التحليل لكل دفعة خامة.',
        'approve_deviations' => 'اعتمد الانحرافات أو أغلقها.',
        'review_out_of_spec' => 'راجع النتائج الخارجة عن المواصفة.',
        'record_results_or_specs' => 'سجّل النتائج أو حدود المواصفة الناقصة.',
        'approve_cost' => 'اعتمد التكلفة.',
        'complete_price_waterfall' => 'أكمل مدخلات سلّم السعر (بما فيها إعداد الضريبة للمنتج).',
        'configure_price_change_threshold' => 'اضبط حد تغيّر السعر في إعدادات القرارات.',
        'reapprove_cost' => 'أعد اعتماد التكلفة بعد مراجعة أسعار الخامات.',
    ],
    'dsl' => [
        'approval_required' => 'المجموعة المنشورة تحتاج اسم من اعتمدها وتاريخ الاعتماد.',
        'approval_not_allowed' => 'الاعتماد يُسجَّل عند النشر فقط، فلا تحمله المسودة.',
    ],
];
