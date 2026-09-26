<!DOCTYPE html>
{{--
  صفحات الدخول: داكنةٌ وحدها في النظام، بشبكة نقاطٍ تتحرّك مع المؤشّر (login.js).
  خطّها IBM Plex Sans Arabic، وتصميمها وألوانها في login.css — لا في app.css،
  فلا يحمّل الدخولُ النظامَ كلّه، ولا يمسّ النظامَ تصميمُ الدخول.
--}}
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0b1020">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'تسجيل الدخول') — @yield('brand', 'وهج العراق')</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/login.css', 'resources/js/login.js'])
</head>
<body>
    <canvas id="network" aria-hidden="true"></canvas>
    <div class="glow" aria-hidden="true"></div>
    <div class="ambient a" aria-hidden="true"></div>
    <div class="ambient b" aria-hidden="true"></div>

    <main>
        @yield('content')
    </main>
</body>
</html>
