<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title')</title>

    @include('partials.fonts')

    @vite(['resources/css/app.css'])
</head>
<body class="grid min-h-screen place-items-center px-4 antialiased">

<div class="ds-header fixed inset-x-0 top-0" aria-hidden="true"></div>

{{--
  صفحات الخطأ بالعربية وبلغة نظام التصميم. لا تقرأ الجلسة ولا القاعدة: بعضها
  يُرسَم قبل أن تُعرَف الشركة أصلاً (نطاقٌ مجهول)، فأيّ استعلامٍ هنا يرمي.

  رسالة abort() تظهر إن كانت عربية فقط — الإنجليزية رسائل داخلية
  («No query results for model…») لا تُعرَض على مستخدم.
--}}
@php
    $message = isset($exception) ? trim((string) $exception->getMessage()) : '';
    $message = preg_match('/\p{Arabic}/u', $message) ? $message : null;

    /*
    | «الصفحة الرئيسية» بيت كل مستخدم كما يوجّهه الدخول: التاجر بوابته،
    | والمندوب مهامه، ومدير المنصّة لوحتها. ولا يُسأل عن المستخدم إلا في
    | سياق شركةٍ أو منصّة — في نطاقٍ مجهول يرمي الاستعلام، وصفحة الخطأ لا
    | يجوز أن تُخطئ.
    */
    $home = '/';
    try {
        if ((\App\Support\Tenancy\Tenancy::has() || \App\Support\Tenancy\Tenancy::isPlatform()) && ($viewer = auth()->user())) {
            $home = match (true) {
                $viewer->isPlatformUser()                              => route('admin.dashboard'),
                $viewer->role === \App\Enums\UserRole::Merchant      => route('portal.dashboard'),
                $viewer->role === \App\Enums\UserRole::Courier       => route('courier.tasks'),
                default                                                => route('dashboard'),
            };
        }
    } catch (\Throwable) {
        $home = '/';
    }
@endphp

<main class="w-full max-w-md text-center">
    <div class="card px-6 py-10 sm:px-10">
        <div class="mx-auto mb-6 grid size-16 place-items-center rounded-xl bg-primary-50 text-primary-700">
            <x-icon :name="trim($__env->yieldContent('icon')) ?: 'alert'" class="size-8"/>
        </div>
        <div class="num text-sm font-medium text-ink-500">@yield('code')</div>
        <h1 class="page-title mt-1">@yield('title')</h1>
        <p class="mt-3 text-[15px] text-ink-600">{{ $message ?? trim($__env->yieldContent('message')) }}</p>

        <div class="mt-8 flex flex-wrap justify-center gap-3">
            <button type="button" onclick="history.back()" class="btn-ghost">رجوع</button>
            <a href="{{ $home }}" class="btn-primary">الصفحة الرئيسية</a>
        </div>
    </div>
</main>

</body>
</html>
