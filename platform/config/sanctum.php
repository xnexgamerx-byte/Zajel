<?php

/*
| رموز دخول التطبيقات (docs/plan/48). التطبيق يحمل رمزاً لا جلسة: لا كعكات ولا
| «نطاقات ذات حالة»، فلا حارس جلسةٍ يُجرَّب قبل الرمز — الموقع بجلسته، والتطبيق برمزه.
*/
return [

    'stateful' => [],

    'guard' => [],

    // يُضبط لكل رمزٍ عند إصداره (سنةٌ من الدخول) — والخروج يُبطله فوراً
    'expiration' => null,

    // بادئةٌ تعرف بها أدوات فحص الأسرار رمزاً نُشر خطأً
    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'zj_'),

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies'      => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token'  => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],

];
