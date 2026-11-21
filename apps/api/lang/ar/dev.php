<?php

return [
    'list_demo' => [
        'title' => 'تجربة مكونات القوائم',
        'description' => 'صفحة تجريبية للتطوير فقط: بحث وفلاتر وترتيب وحفظ الحالة في الرابط.',
        'columns' => [
            'trade_name' => 'اسم الخامة',
            'grade' => 'الدرجة',
            'function' => 'الوظيفة',
            'status' => 'الحالة',
            'is_mixture' => 'خليط',
            'created_at' => 'تاريخ الإضافة',
        ],
        'filters' => [
            'is_mixture' => 'خلائط فقط',
            'function' => 'الوظيفة',
        ],
        'yes' => 'نعم',
        'no' => 'لا',
        'local_select' => 'اختيار من قائمة محلية',
        'remote_select' => 'اختيار بالبحث من السيرفر',
        'select_hint' => 'اكتب جزء من الاسم للبحث.',
        'selected' => 'المحدد',
    ],
];
