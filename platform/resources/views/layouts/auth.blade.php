<!DOCTYPE html>
{{--
  صفحات الدخول (docs/plan/105): بطاقةٌ بيضاء، في جهتها رسمُ لوحة دوائر بطبقات ورقٍ مقصوص يسري التيار في
  مساراتها (auth/partials/art)، وفي الأخرى النموذج وفوقه شعار «وهج العراق» صاحب النواة. للشركات ولوحة المنصّة
  معاً، فلا اسم شركةٍ ولا لونها هنا. تصميمها في login.css وحده، فلا يحمّل الدخولُ النظامَ كلّه.
--}}
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#e7ebf1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'تسجيل الدخول') — @yield('brand', 'وهج العراق')</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=JetBrains+Mono:wght@800&display=swap" rel="stylesheet">

    @vite(['resources/css/login.css', 'resources/js/login.js'])
</head>
<body>
    {{-- أشياءُ برمجيّة مغبّشة خلف البطاقة، كأنّها خارج عمق الصورة --}}
    <div class="bokeh" aria-hidden="true">
        <div class="win"></div>
        <div class="orb"></div>
        <div class="tag">&lt;/&gt;</div>
        <div class="brace">{ }</div>
        <div class="dots"></div>
    </div>

    <main class="sheet">
        <div class="art" aria-hidden="true">
            @include('auth.partials.art')
        </div>

        <section class="form-side">
            <header class="wahaj">
                <x-wahaj-logo class="wahaj-logo"/>
                <span>
                    <b>وهج العراق</b>
                    <small>@yield('brand-sub', 'نظام إدارة الشحنات')</small>
                </span>
            </header>

            @yield('content')
        </section>
    </main>
</body>
</html>
