<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'لوحة زاجل') — إدارة المنصّة</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 antialiased">

@auth
{{-- شريط داكن يميّز لوحة النواة عن لوحات الشركات بلا لبس --}}
<header class="sticky top-0 z-30 bg-slate-900 text-white">
    <div class="mx-auto flex h-14 max-w-screen-2xl items-center gap-4 px-4">
        <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-2 font-bold">
            <span class="grid h-8 w-8 place-items-center rounded-lg bg-brand-500 text-sm font-black">ز</span>
            <span>زاجل — إدارة المنصّة</span>
        </a>

        <nav class="hidden items-center gap-1 text-sm md:flex">
            @foreach ([
                ['admin.dashboard', 'الرئيسية', 'admin.dashboard'],
                ['admin.companies.index', 'الشركات', 'admin.companies.*'],
                ['admin.plans.index', 'الباقات', 'admin.plans.*'],
            ] as [$route, $label, $pattern])
                <a href="{{ route($route) }}"
                   class="rounded-lg px-3 py-1.5 font-medium {{ request()->routeIs($pattern) ? 'bg-white/15 text-white' : 'text-slate-300 hover:bg-white/10' }}">
                    {{ $label }}
                </a>
            @endforeach
        </nav>

        <div class="ms-auto flex items-center gap-3">
            <div class="hidden text-left sm:block">
                <div class="text-sm font-semibold leading-tight">{{ auth()->user()->name }}</div>
                <div class="text-xs text-slate-400">{{ auth()->user()->role->label() }}</div>
            </div>
            <form method="POST" action="{{ route('admin.logout') }}">
                @csrf
                <button class="rounded-lg px-3 py-1.5 text-sm text-slate-300 hover:bg-white/10">خروج</button>
            </form>
        </div>
    </div>
</header>
@endauth

<main class="mx-auto max-w-screen-2xl px-4 py-6">
    @if (session('success'))
        <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 ring-1 ring-emerald-200">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->any() && ! request()->routeIs('admin.login'))
        <div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800 ring-1 ring-red-200">
            <div class="font-semibold">تعذّر الحفظ:</div>
            <ul class="mt-1 list-disc ps-5">
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    @yield('content')
</main>

</body>
</html>
