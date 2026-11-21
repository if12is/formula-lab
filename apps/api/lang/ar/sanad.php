<?php

return [
    'unauthenticated' => 'مفتاح جهاز سند مفقود أو غير صالح.',
    'ability_missing' => 'هذا المفتاح لا يملك الصلاحية المطلوبة.',
    'device_mismatch' => 'هذا المفتاح مربوط بجهاز سند آخر.',
    'device_uuid_invalid' => 'ترويسة X-Device-Uuid يجب أن تكون UUID صالحاً.',
    'idempotency_key_required' => 'ترويسة Idempotency-Key مطلوبة (255 حرفاً كحد أقصى).',
    'idempotency_mismatch' => 'مفتاح التكرار مستخدم مع بيانات مختلفة.',
    'request_in_flight' => 'الطلب قيد التنفيذ.',
    'throttled' => 'طلبات كثيرة، حاول لاحقاً.',
    'command' => [
        'created' => 'تم إنشاء جهاز سند ":name".',
        'token_notice' => 'المفتاح (يظهر مرة واحدة، احفظه في سند الآن): :token',
        'tenant_unresolved' => 'مرر --tenant=<id>: تعذر اختيار المستأجر تلقائياً.',
        'tenant_unknown' => 'المستأجر :tenant غير موجود.',
        'ability_unknown' => 'صلاحية غير معروفة :ability.',
        'mock_url' => 'عنوان سند التجريبي: :url (المفتاح: :token)',
        'pruned' => 'تم حذف :count مفتاح تكرار منتهي.',
    ],
    'mock' => [
        'unknown_product' => 'الصنف :product غير معروف في سند التجريبي.',
        'insufficient_stock' => 'الرصيد غير كافٍ.',
        'insufficient_stock_line' => 'الرصيد غير كافٍ.',
        'order_conflict' => 'تمت معالجة أمر الإنتاج هذا ببيانات مختلفة.',
        'not_consumed' => 'لم يتم صرف الخامات لأمر الإنتاج أولاً.',
        'not_finished_good' => 'الصنف ليس منتجاً تاماً.',
        'unauthenticated' => 'Unauthorized',
        'ability_missing' => 'الصلاحية غير كافية.',
    ],
];
