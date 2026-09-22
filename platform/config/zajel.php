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
];
