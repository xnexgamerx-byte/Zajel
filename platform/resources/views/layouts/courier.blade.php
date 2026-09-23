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
    <link href="https://fonts.googleapis.com/css2?family=Readex+Pro:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>:root { --brand: {{ $company->primary_color }}; }</style>
</head>
<body class="min-h-screen pb-28 antialiased">

<header class="sticky top-0 z-30 bg-ink-50/90 backdrop-blur">
    <div class="flex h-16 items-center gap-3 px-4">
        <span class="grid size-10 shrink-0 place-items-center rounded-2xl border border-ink-900 text-base font-bold text-white"
              style="background: var(--brand)">ز</span>
        <div class="min-w-0 flex-1">
            <div class="truncate text-[15px] font-semibold leading-tight">{{ $courier->name }}</div>
            <div class="truncate text-xs text-ink-500">{{ $company->name }}</div>
        </div>
        @php $unread = \App\Models\Announcement::for(auth()->user())->unreadBy(auth()->user())->count(); @endphp
        <a href="{{ route('courier.inbox') }}" class="icon-btn"
           aria-label="الإشعارات{{ $unread ? '، غير المقروء '.$unread : '' }}">
            <x-icon name="bell" class="size-[18px]"/>
            @if ($unread)
                <span class="num absolute -end-1 -top-1 grid min-w-5 place-items-center rounded-full border border-white px-1 text-[11px] font-semibold text-white" style="background: var(--brand)">{{ $unread > 9 ? '9+' : $unread }}</span>
            @endif
        </a>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="icon-btn" aria-label="خروج" title="خروج">
                <x-icon name="logout" class="size-[18px] rtl:-scale-x-100"/>
            </button>
        </form>
    </div>
</header>

<main class="px-3 pb-4 pt-1">
    @if (session('success'))
        <div class="alert mb-3 border-ink-900 bg-ok-700 font-medium text-white" role="status">
            <x-icon name="check" class="size-5 shrink-0"/>
            <span>{{ session('success') }}</span>
        </div>
    @endif

    @if ($errors->any())
        <div class="alert mb-3 border-ink-900 bg-bad-700 font-medium text-white" role="alert">
            <x-icon name="alert" class="size-5 shrink-0"/>
            <ul class="space-y-0.5">
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    @yield('content')
</main>

{{-- شريط سفلي: الإبهام يصله بلا مدّ اليد — حبّةٌ عائمة، والحالية داكنة --}}
<nav class="fixed inset-x-3 z-30" style="bottom: calc(0.75rem + env(safe-area-inset-bottom, 0px))">
    @php
        /*
        | الشريط يتبع دور المندوب: مندوب الاستلام لا نقد بيده فـ«حسابي»
        | عنده شاشة فارغة، وحسابه الحقيقي حصصه. ومندوب التوصيل لا حصص له.
        */
        $me = auth()->user()->courier;

        $tabs = collect([
            ['courier.tasks', 'مهامي', 'courier.tasks', $me?->delivers(), 'box'],
            ['courier.pickups', 'استلام', 'courier.pickups', $me?->picks(), 'clipboard'],
            ['courier.today', 'اليوم', 'courier.today', $me?->delivers(), 'calendar'],
            ['courier.shares', 'حصصي', 'courier.shares', $me?->picks(), 'wallet'],
            ['courier.cash', 'حسابي', 'courier.cash', $me?->delivers(), 'cash'],
        ])->filter(fn ($tab) => $tab[3])->values();
    @endphp

    <div class="grid gap-1 rounded-[28px] border border-ink-900 bg-white p-1.5 shadow-2xl"
         style="grid-template-columns: repeat({{ max(1, $tabs->count()) }}, minmax(0, 1fr))">
        @foreach ($tabs as [$route, $label, $pattern, $shown, $icon])
            @php $active = request()->routeIs($pattern); @endphp
            <a href="{{ route($route) }}"
               class="flex flex-col items-center gap-1 rounded-[22px] py-2 text-xs font-medium transition
                      {{ $active ? 'bg-ink-900 text-white' : 'text-ink-600 hover:bg-ink-100' }}"
               @if ($active) aria-current="page" @endif>
                <x-icon :name="$icon" class="size-5 {{ $active ? 'text-sun' : '' }}"/>
                {{ $label }}
            </a>
        @endforeach
    </div>
</nav>

</body>
</html>
