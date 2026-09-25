<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- صفحات الزبون لا تُفهرَس: رابط التتبّع يخصّ صاحبه --}}
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'تتبّع شحنة') — {{ $company->name }}</title>

    @include('partials.fonts')

    @vite(['resources/css/app.css'])
    <style>:root { --brand: {{ $company->primary_color }}; }</style>
</head>
<body class="min-h-screen bg-ink-50 antialiased">

<header class="ds-header border-b border-ink-200">
    <div class="mx-auto flex max-w-2xl items-center gap-3 px-4 py-3">
        <span class="brand-tile">ز</span>
        <div class="min-w-0">
            <div class="truncate font-heading text-lg leading-tight font-bold text-aeblack-900">{{ $company->name }}</div>
            <div class="text-xs text-ink-500">تتبّع الشحنات</div>
        </div>
    </div>
</header>

<main class="mx-auto max-w-2xl px-4 pt-6 pb-12">
    @yield('content')

    {{-- أوّل ما يسأله زبونٌ قلق: بمن أتّصل؟ --}}
    @php
        $whatsapp = \App\Support\Phone::whatsappUrl($company->setting('support.whatsapp'));
    @endphp
    @if ($whatsapp || $company->phone)
        <section class="card mt-5 flex flex-col gap-4 p-5 sm:flex-row sm:items-center">
            <div class="min-w-0 sm:flex-1">
                <div class="card-title">عندك سؤال عن شحنتك؟</div>
                <p class="card-hint">
                    تواصل مع {{ $company->name }}
                    @if ($hours = $company->setting('support.hours')) · {{ $hours }} @endif
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
            @if ($whatsapp)
                <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="btn-primary">
                    <x-icon name="chat" class="size-5"/>
                    واتساب
                </a>
            @endif
            @if ($company->phone)
                <a href="tel:{{ $company->phone }}" class="btn-ghost">
                    <span class="num">{{ $company->phone }}</span>
                </a>
            @endif
            </div>
        </section>
    @endif
</main>

</body>
</html>
