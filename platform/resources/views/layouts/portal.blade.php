<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'بوابة التاجر') — {{ $company->name }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- البوابة تحمل علامة شركة التوصيل لا علامة المنصّة: التاجر
         يتعامل مع "الزاجل" لا مع "زاجل المنصّة". --}}
    <style>:root { --brand: {{ $company->primary_color }}; }</style>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">

<header class="sticky top-0 z-30 border-b border-slate-200 bg-white/90 backdrop-blur">
    <div class="mx-auto flex h-14 max-w-screen-xl items-center gap-4 px-4">
        <a href="{{ route('portal.dashboard') }}" class="flex items-center gap-2 font-bold">
            <span class="grid h-8 w-8 place-items-center rounded-lg text-sm font-black text-white"
                  style="background: var(--brand)">ز</span>
            <span>{{ $company->name }}</span>
        </a>

        <nav class="hidden items-center gap-1 text-sm md:flex">
            @foreach ([
                ['portal.dashboard', 'الرئيسية', 'portal.dashboard'],
                ['portal.shipments.index', 'شحناتي', 'portal.shipments.index'],
                ['portal.shipments.create', 'شحنة جديدة', 'portal.shipments.create'],
                ['portal.pickups.index', 'طلبات الاستلام', 'portal.pickups.*'],
                ['portal.statement', 'حسابي', 'portal.statement'],
            ] as [$route, $label, $pattern])
                <a href="{{ route($route) }}"
                   class="rounded-lg px-3 py-1.5 font-medium {{ request()->routeIs($pattern) ? 'bg-slate-100 text-slate-900' : 'text-slate-600 hover:bg-slate-50' }}">
                    {{ $label }}
                </a>
            @endforeach
        </nav>

        <div class="ms-auto flex items-center gap-3">
            <div class="hidden text-left sm:block">
                <div class="text-sm font-semibold leading-tight">{{ $merchant->business_name }}</div>
                <div class="font-mono text-xs text-slate-500" dir="ltr">{{ $merchant->code }}</div>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="rounded-lg px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-100">خروج</button>
            </form>
        </div>
    </div>

    {{-- تنقّل الهاتف: التاجر يفتح هذا من جواله غالباً --}}
    <nav class="flex gap-1 overflow-x-auto border-t border-slate-100 px-2 py-1.5 text-sm md:hidden">
        @foreach ([
            ['portal.dashboard', 'الرئيسية'],
            ['portal.shipments.index', 'شحناتي'],
            ['portal.shipments.create', 'جديدة'],
            ['portal.pickups.index', 'استلام'],
            ['portal.statement', 'حسابي'],
        ] as [$route, $label])
            <a href="{{ route($route) }}"
               class="shrink-0 rounded-lg px-3 py-1.5 font-medium {{ request()->routeIs($route) ? 'bg-slate-100 text-slate-900' : 'text-slate-600' }}">
                {{ $label }}
            </a>
        @endforeach
    </nav>
</header>

<main class="mx-auto max-w-screen-xl px-4 py-6">
    @if (session('success'))
        <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 ring-1 ring-emerald-200">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800 ring-1 ring-red-200">
            <div class="font-semibold">راجع الحقول التالية:</div>
            <ul class="mt-1 list-disc ps-5">
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    @yield('content')
</main>

</body>
</html>
