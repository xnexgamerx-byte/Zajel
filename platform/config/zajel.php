<?php

return [
    /*
     | النطاق الأساسي الذي تتفرّع منه أنظمة الشركات:
     | zajel.zajel.iq · barq.zajel.iq · admin.zajel.iq
     */
    'tenant_domain' => env('ZAJEL_TENANT_DOMAIN', 'zajel.iq'),

    /*
     | نظامٌ بلا نطاق: عنوان Railway المجاني (xxx.up.railway.app) عنوانٌ
     | واحد، لا نطاقات فرعية. فتُخدَم عليه — وعلى كل عنوانٍ ليس نطاقاً
     | فرعياً لـ tenant_domain — هذه الشركة وحدها، ولوحة المنصّة تحت /admin.
     | فارغٌ (الافتراضي): لا شركة إلا بنطاقها الفرعي.
     */
    'default_company' => env('ZAJEL_DEFAULT_COMPANY'),

    /*
     | العملة — دينار عراقي بلا كسور. كل المبالغ BIGINT بالدينار الصحيح.
     */
    'currency' => [
        'code'   => 'IQD',
        'symbol' => 'د.ع',
    ],

    /*
     | عدد الصفوف في شاشات القوائم.
     */
    'per_page' => 25,

    /*
    | شحنة لم تتغيّر حالتها منذ هذه المدّة تُعدّ متعثّرة. خمسة أيام
    | عرفٌ عراقيّ معقول: أطول من دورة توصيل عادية وأقصر من أن يُنسى
    | الطرد.
    */
    'stale_shipment_days' => 5,

    /*
    | أوّل مدير منصّة على خادمٍ بلا طرفية (zajel:bootstrap). يُقرأ مرّةً واحدة
    | حين لا مدير، ثم تُحذف كلمة المرور من الإعدادات.
    */
    'bootstrap_admin' => [
        'phone'    => env('ZAJEL_ADMIN_PHONE'),
        'password' => env('ZAJEL_ADMIN_PASSWORD'),
    ],
];
