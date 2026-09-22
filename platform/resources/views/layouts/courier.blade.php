<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="{{ $company->primary_color }}">
    <title>@yield('title', 'مهامي') — {{ $company->name }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>:root { --brand: {{ $company->primary_color }}; }</style>
</head>
<body class="min-h-screen pb-20 antialiased">

<header class="sticky top-0 z-30 text-white" style="background: var(--brand)">
    <div class="flex h-14 items-center gap-3 px-4">
        <div class="min-w-0 flex-1">
            <div class="truncate text-sm font-bold">{{ $courier->name }}</div>
            <div class="truncate text-xs opacity-80">{{ $company->name }}</div>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="rounded-lg px-3 py-1.5 text-sm hover:bg-white/15">خروج</button>
        </form>
    </div>
</header>

<main class="px-3 py-4">
    @if (session('success'))
        <div class="mb-3 rounded-xl bg-ok-700 px-4 py-3 text-sm font-semibold text-white">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-3 rounded-xl bg-bad-700 px-4 py-3 text-sm font-medium text-white">
            <ul class="space-y-0.5">
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    @yield('content')
</main>

{{-- شريط سفلي: الإبهام يصله بلا مدّ اليد --}}
<nav class="fixed inset-x-0 bottom-0 z-30 border-t border-ink-200 bg-white"
     style="padding-bottom: env(safe-area-inset-bottom, 0px)">
    @php
        /*
        | الشريط يتبع دور المندوب: مندوب الاستلام لا نقد بيده فـ«حسابي»
        | عنده شاشة فارغة، وحسابه الحقيقي حصصه. ومندوب التوصيل لا حصص له.
        */
        $me = auth()->user()->courier;

        $tabs = collect([
            ['courier.tasks', 'مهامي', 'courier.tasks', $me?->delivers()],
            ['courier.pickups', 'استلام', 'courier.pickups', $me?->picks()],
            ['courier.today', 'اليوم', 'courier.today', $me?->delivers()],
            ['courier.shares', 'حصصي', 'courier.shares', $me?->picks()],
            ['courier.cash', 'حسابي', 'courier.cash', $me?->delivers()],
        ])->filter(fn ($tab) => $tab[3])->values();
    @endphp

    <div class="grid" style="grid-template-columns: repeat({{ max(1, $tabs->count()) }}, minmax(0, 1fr))">
        @foreach ($tabs as [$route, $label, $pattern, $shown])
            <a href="{{ route($route) }}"
               class="flex flex-col items-center gap-0.5 py-2.5 text-xs font-semibold
                      {{ request()->routeIs($pattern) ? 'text-[var(--brand)]' : 'text-ink-500' }}">
                <span class="h-1.5 w-1.5 rounded-full {{ request()->routeIs($pattern) ? 'bg-[var(--brand)]' : 'bg-transparent' }}"></span>
                {{ $label }}
            </a>
        @endforeach
    </div>
</nav>

</body>
</html>
