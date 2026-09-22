<?php

return [
    /*
     | النطاق الأساسي الذي تتفرّع منه أنظمة الشركات:
     | zajel.zajel.iq · barq.zajel.iq · admin.zajel.iq
     */
    'tenant_domain' => env('ZAJEL_TENANT_DOMAIN', 'zajel.iq'),

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
];
