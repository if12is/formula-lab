<?php

return [
    'command' => [
        'master_key_generated' => 'مفتاح رئيسي جديد (حطه في بيئة السيرفر، ماتحطهوش في الريبو ولا في نسخة قاعدة البيانات):',
        'dry_run' => 'تشغيل تجريبي: مفيش حاجة اتكتبت.',
        'rewrap_summary' => 'عملاء: :tenants، متغلّفين من جديد: :rewrapped، على المفتاح الحالي أصلًا: :current، من غير مفتاح: :without_key.',
        'rewrap_next' => 'بعد ما كل العملاء يبقوا على المفتاح الحالي شيل APP_MASTER_KEY_PREVIOUS.',
        'encrypt_summary' => 'نسخ اتفحصت: :scanned، محتاجة تشفير: :pending، اتشفّرت: :encrypted، أعمدة اتشفّرت: :columns، متشفّرة أصلًا: :already.',
        'encrypt_failure' => 'فشل في النسخة :version: :reason',
        'encrypt_hash_mismatch' => 'بصمة النسخة المسجلة مش بتطابق محتواها قبل التشفير، فمالمستش النسخة دي',
        'encrypt_ok' => 'تمام: كل النسخ اتشفّرت والبصمات متطابقة قبل وبعد.',
        'tenant_unknown' => 'مفيش عميل بالمعرّف :tenant.',
    ],
];
