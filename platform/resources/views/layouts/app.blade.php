<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'زاجل') — {{ $company->name ?? 'زاجل' }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @isset($company)
        <style>:root { --brand: {{ $company->primary_color }}; }</style>
    @endisset
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">

@auth
@if (session()->has(\App\Actions\Platform\ImpersonateCompany::SESSION_KEY))
    {{-- شريط لا يُخطأ: من يعمل داخل نظام شركة يجب أن يعرف أنه ليس نفسه --}}
    <div class="sticky top-0 z-40 bg-amber-500 text-amber-950">
        <div class="mx-auto flex max-w-screen-2xl flex-wrap items-center gap-3 px-4 py-2 text-sm">
            <span class="font-semibold">
                أنت داخل نظام {{ $company->name }} من لوحة المنصّة — هذا الدخول مسجَّل في سجلّ الشركة.
            </span>
            <form method="POST" action="{{ route('impersonation.stop') }}" class="ms-auto">
                @csrf
                <button class="rounded-lg bg-amber-950/10 px-3 py-1 font-semibold hover:bg-amber-950/20">
                    عُد إلى لوحة المنصّة
                </button>
            </form>
        </div>
    </div>
@endif

<header class="sticky top-0 z-30 border-b border-slate-200 bg-white/90 backdrop-blur">
    <div class="mx-auto flex h-14 max-w-screen-2xl items-center gap-4 px-4">
        <a href="{{ route('shipments.index') }}" class="flex items-center gap-2 font-bold">
            <span class="grid h-8 w-8 place-items-center rounded-lg text-sm font-black text-white"
                  style="background: var(--brand, #0d9488)">ز</span>
            <span>{{ $company->name }}</span>
        </a>

        <nav class="hidden items-center gap-1 text-sm md:flex">
            @php $staff = auth()->user()->isStaff(); @endphp
            <a href="{{ route('shipments.index') }}"
               class="rounded-lg px-3 py-1.5 font-medium {{ request()->routeIs('shipments.index') ? 'bg-slate-100 text-slate-900' : 'text-slate-600 hover:bg-slate-50' }}">
                الشحنات
            </a>
            @if ($staff)
            <a href="{{ route('shipments.create') }}"
               class="rounded-lg px-3 py-1.5 font-medium {{ request()->routeIs('shipments.create') ? 'bg-slate-100 text-slate-900' : 'text-slate-600 hover:bg-slate-50' }}">
                شحنة جديدة
            </a>
            <a href="{{ route('merchants.index') }}"
               class="rounded-lg px-3 py-1.5 font-medium {{ request()->routeIs('merchants.*') ? 'bg-slate-100 text-slate-900' : 'text-slate-600 hover:bg-slate-50' }}">
                التجّار
            </a>
            <a href="{{ route('couriers.index') }}"
               class="rounded-lg px-3 py-1.5 font-medium {{ request()->routeIs('couriers.index') || request()->routeIs('couriers.show') || request()->routeIs('couriers.create') || request()->routeIs('couriers.edit') ? 'bg-slate-100 text-slate-900' : 'text-slate-600 hover:bg-slate-50' }}">
                المندوبون
            </a>
            <a href="{{ route('settlements.couriers.index') }}"
               class="rounded-lg px-3 py-1.5 font-medium {{ request()->routeIs('settlements.couriers.*') ? 'bg-slate-100 text-slate-900' : 'text-slate-600 hover:bg-slate-50' }}">
                تسوية المندوبين
            </a>
            <a href="{{ route('settlements.merchants.index') }}"
               class="rounded-lg px-3 py-1.5 font-medium {{ request()->routeIs('settlements.merchants.*') ? 'bg-slate-100 text-slate-900' : 'text-slate-600 hover:bg-slate-50' }}">
                تسوية التجّار
            </a>
            @endif
        </nav>

        <div class="ms-auto flex items-center gap-3">
            <div class="hidden text-left sm:block">
                <div class="text-sm font-semibold leading-tight">{{ auth()->user()->name }}</div>
                <div class="text-xs text-slate-500">{{ auth()->user()->role->label() }}</div>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="rounded-lg px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-100">خروج</button>
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

    @if ($errors->any() && ! request()->routeIs('login'))
        <div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800 ring-1 ring-red-200">
            <div class="font-semibold">تعذّر الحفظ:</div>
            <ul class="mt-1 list-disc ps-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @yield('content')
</main>

</body>
</html>
