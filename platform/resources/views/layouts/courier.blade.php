<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="{{ $company->primary_color }}">
    <title>@yield('title', 'مهامي') — {{ $company->name }}</title>

    @include('partials.fonts')

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>:root { --brand: {{ $company->primary_color }}; }</style>
</head>
<body class="min-h-screen bg-ink-50 pb-24 antialiased">

<header class="ds-header sticky top-0 z-30 border-b border-ink-200">
    <div class="flex h-16 items-center gap-3 px-4">
        <span class="brand-tile size-10 text-base">{{ $company->initial() }}</span>
        <div class="min-w-0 flex-1">
            <div class="truncate font-heading text-[15px] leading-tight font-bold text-aeblack-900">{{ $courier->name }}</div>
            <div class="truncate text-xs text-ink-500">{{ $company->name }}</div>
        </div>
        @php $unread = \App\Models\Announcement::for(auth()->user())->unreadBy(auth()->user())->count(); @endphp
        <a href="{{ route('courier.inbox') }}" class="icon-btn"
           aria-label="الإشعارات{{ $unread ? '، غير المقروء '.$unread : '' }}">
            <x-icon name="bell" class="size-5"/>
            @if ($unread)
                <span class="nav-badge absolute -end-1 -top-1">{{ $unread > 9 ? '9+' : $unread }}</span>
            @endif
        </a>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="icon-btn" aria-label="خروج" title="خروج">
                <x-icon name="logout" class="size-5 rtl:-scale-x-100"/>
            </button>
        </form>
    </div>
</header>

<main class="px-3 pt-3 pb-4">
    {{-- التنبيه ممتلئٌ هنا: المندوب يقرؤه في الشارع وتحت الشمس --}}
    @if (session('success'))
        <div class="alert mb-3 bg-aegreen-700 font-medium text-white" role="status">
            <x-icon name="check" class="size-5 shrink-0"/>
            <span>{{ session('success') }}</span>
        </div>
    @endif

    @if ($errors->any())
        <div class="alert mb-3 bg-aered-700 font-medium text-white" role="alert">
            <x-icon name="alert" class="size-5 shrink-0"/>
            <ul class="space-y-0.5">
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    @yield('content')
</main>

{{-- شريط سفلي: الإبهام يصله بلا مدّ اليد. والتبويب الحاليّ يعلوه خطّ النظام الذهبيّ --}}
<nav class="fixed inset-x-0 bottom-0 z-30 border-t border-ink-200 bg-white"
     style="padding-bottom: env(safe-area-inset-bottom, 0px)">
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

    <div class="grid" style="grid-template-columns: repeat({{ max(1, $tabs->count()) }}, minmax(0, 1fr))">
        @foreach ($tabs as [$route, $label, $pattern, $shown, $icon])
            @php $active = request()->routeIs($pattern); @endphp
            <a href="{{ route($route) }}"
               class="flex flex-col items-center gap-1 border-t-[3px] pt-2 pb-2.5 text-xs font-bold transition-colors
                      {{ $active ? 'border-primary-600 text-primary-700' : 'border-transparent text-aeblack-600 hover:text-primary-700' }}"
               @if ($active) aria-current="page" @endif>
                <x-icon :name="$icon" class="size-6"/>
                {{ $label }}
            </a>
        @endforeach
    </div>
</nav>

</body>
</html>
