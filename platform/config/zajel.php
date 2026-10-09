<?php

return [
    /*
     | النطاق الأساسي الذي تتفرّع منه أنظمة الشركات:
     | zajel.wahaj.iq · barq.wahaj.iq · admin.wahaj.iq
     |
     | بأحرفٍ صغيرة وبلا مسافاتٍ أو علامات تنصيص: يُكتب في متغيّرات
     | الاستضافة باليد، والمضيف يُقارَن به حرفاً بحرف.
     */
    'tenant_domain' => strtolower(trim((string) env('ZAJEL_TENANT_DOMAIN', 'wahaj.iq'), " \t\n\r\"'")),

    /*
     | نظامٌ بلا نطاق: عنوان Railway المجاني (xxx.up.railway.app) عنوانٌ
     | واحد، لا نطاقات فرعية. فتُخدَم عليه — وعلى كل عنوانٍ ليس نطاقاً
     | فرعياً لـ tenant_domain — هذه الشركة وحدها، ولوحة المنصّة تحت /admin.
     | فارغٌ (الافتراضي): لا شركة إلا بنطاقها الفرعي.
     */
    'default_company' => strtolower(trim((string) env('ZAJEL_DEFAULT_COMPANY'), " \t\n\r\"'")),

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

    /*
    | قراءة الطلب بالذكاء الاصطناعي (docs/plan/40): صورة الطلب أو رسالته أو كلام التاجر
    | يقرؤها Claude ويُرجعها حقولاً. بلا مفتاحٍ — أو إن تعذّرت القراءة — يقرأ القارئ المحلّي
    | كما كان. المفتاح في .env لا في المستودع.
    */
    'ai' => [
        'key'     => env('ANTHROPIC_API_KEY'),
        'model'   => env('ZAJEL_AI_MODEL', 'claude-opus-5-5'),
        'timeout' => (float) env('ZAJEL_AI_TIMEOUT', 40),
    ],

    /*
    | قراءة الطلب من لقطة شاشة على الخادم نفسه (Tesseract، docs/plan/34): لا يخرج
    | منها شيء. وبلا البرنامج تبقى قراءة النصّ الملصوق وحدها.
    */
    'ocr' => [
        'binary' => env('ZAJEL_OCR_BINARY', 'tesseract'),
    ],
];
