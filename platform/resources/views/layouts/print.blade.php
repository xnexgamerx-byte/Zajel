<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'طباعة') — {{ $company->name ?? 'زاجل' }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Readex+Pro:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css'])

    @isset($company)
        <style>:root { --brand: {{ $company->primary_color }}; }</style>
    @endisset
</head>
<body class="bg-ink-100 antialiased print:bg-white">

{{--
  شريط لا يُطبَع: الورقة تخرج من يد الموظّف إلى يد المندوب، فكل ما
  على الشاشة ممّا لا يُقرأ على الورق يُخفى بـ print:hidden لا بالحيلة.
--}}
<div class="sticky top-0 z-10 border-b border-ink-200 bg-white print:hidden">
    <div class="mx-auto flex max-w-[210mm] flex-wrap items-center gap-3 px-4 py-3">
        <a href="{{ $back ?? url()->previous() }}" class="btn-ghost">رجوع</a>
        <span class="text-sm text-ink-500">@yield('title')</span>
        <button type="button" onclick="window.print()" class="btn-primary ms-auto">اطبع</button>
    </div>
</div>

<main class="mx-auto my-6 max-w-[210mm] bg-white p-8 shadow-sm print:my-0 print:max-w-none print:p-0 print:shadow-none">
    <header class="mb-6 flex items-start justify-between gap-4 border-b-2 border-ink-900 pb-4">
        <div>
            <h1 class="text-2xl font-black">{{ $company->name ?? 'زاجل' }}</h1>
            <p class="mt-0.5 text-sm text-ink-600">@yield('subtitle')</p>
        </div>
        <div class="text-end text-xs text-ink-600">
            <div class="text-base font-bold text-ink-900">@yield('docTitle', 'مستند')</div>
            {{-- الوقت على الورقة: بلا ساعةٍ لا يُعرَف أيّ نسخةٍ بيد مَن --}}
            <div class="num mt-1">{{ now()->format('Y-m-d H:i') }}</div>
        </div>
    </header>

    @yield('content')

    <footer class="mt-8 flex items-end justify-between gap-8 border-t border-ink-300 pt-4 text-xs text-ink-600">
        @yield('signatures')
    </footer>
</main>

</body>
</html>
