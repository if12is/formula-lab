<?php

/* Wording of the shared decision card and the override dialog (Y-04, Y-08). Shared with the interface, so {name} placeholders. */
return [
    'override' => [
        'button' => 'تجاوز القرار ده',
        'title' => 'تجاوز قرار ممنوع',
        'description' => 'الاستمرار رغم شرط فاشل بيتسجل للأبد: إنت مين، دورك، سببك والوقت. والشروط الفاشلة بتفضل في السجل.',
        'reason' => 'السبب المكتوب',
        'reason_hint' => 'ليه الاستمرار صح؟ بيتحفظ مع توقيعك ومينفعش يتعدل بعدين.',
        'password' => 'كلمة السر بتاعتك',
        'password_hint' => 'التوقيع محتاج كلمة السر تاني.',
        'confirm' => 'وقّع وتجاوز',
        'recorded' => 'التجاوز اتوقّع واتسجل. القرار ممكن يكمّل دلوقتي.',
        'why_not_signed_in' => 'التجاوز محتاج مستخدم مسجّل دخول، والدخول لسه مش متاح.',
        'why_role' => 'دورك مش مسموح له يتجاوز قرار ممنوع.',
        'active_title' => 'اتجاوز',
        'active_description' => 'القرار الممنوع ده اتجاوز واتوقّع. والشروط الفاشلة فوق لسه موجودة في السجل.',
        'by' => 'وقّعه',
        'role' => 'الدور',
        'at' => 'وقت التوقيع',
        'reason_label' => 'السبب',
        'unknown_user' => 'مستخدم غير معروف',
    ],
    'card' => [
        'allowed' => 'كل الشروط متحققة.',
        'blocked' => 'ممنوع: الشروط دي فاشلة.',
        'undecidable' => 'القواعد ما قدرتش تتحكم: {detail}',
        'failed_count' => '{count} فاشل',
        'step' => 'الخطوة المطلوبة',
        'rule_set' => 'القواعد {id}، النسخة {version}',
        'hash' => 'بصمة القرار',
        'passed' => 'الشروط المتحققة',
    ],
];
