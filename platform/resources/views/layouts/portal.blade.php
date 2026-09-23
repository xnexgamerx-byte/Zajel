<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'بوابة التاجر') — {{ $company->name }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Readex+Pro:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- البوابة تحمل علامة شركة التوصيل لا علامة المنصّة: التاجر
         يتعامل مع "الزاجل" لا مع "زاجل المنصّة". --}}
    <style>:root { --brand: {{ $company->primary_color }}; }</style>
</head>
<body class="min-h-screen antialiased">

{{-- رأس التصميم كما هو: الشعار، ثم القائمة الحبّيّة، ثم الجرس والحساب --}}
@php
    $portalNav = [
        ['portal.dashboard', 'الرئيسية', 'portal.dashboard', 'home', 'الرئيسية'],
        ['portal.shipments.index', 'شحناتي', 'portal.shipments.index', 'box', 'شحناتي'],
        ['portal.shipments.create', 'شحنة جديدة', 'portal.shipments.create', 'plus', 'جديدة'],
        ['portal.shipments.import', 'رفع من ملف', 'portal.shipments.import*', 'upload', 'رفع'],
        ['portal.pickups.index', 'طلبات الاستلام', 'portal.pickups.*', 'clipboard', 'استلام'],
        ['portal.statement', 'حسابي', 'portal.statement', 'wallet', 'حسابي'],
        ['portal.support.index', 'الدعم', 'portal.support.*', 'chat', 'الدعم'],
    ];
    $replies = \App\Models\Conversation::where('merchant_id', auth()->user()->merchant_id)->where('merchant_unread', true)->count();
@endphp
<header class="sticky top-0 z-30 bg-ink-50/85 backdrop-blur">
    <div class="mx-auto flex h-[4.5rem] max-w-screen-xl items-center gap-4 px-4">
        <a href="{{ route('portal.dashboard') }}" class="flex shrink-0 items-center gap-2.5">
            <span class="grid size-10 place-items-center rounded-2xl border border-ink-900 text-base font-bold text-white"
                  style="background: var(--brand)">ز</span>
            <span class="text-lg font-semibold">{{ $company->name }}</span>
        </a>

        <nav class="pill-nav hidden lg:flex">
            @foreach ($portalNav as [$route, $label, $pattern, $icon])
                @php $active = request()->routeIs($pattern); @endphp
                <a href="{{ route($route) }}" class="pill-nav-item {{ $active ? 'pill-nav-item-active' : '' }}"
                   @if ($active) aria-current="page" @endif>
                    @if ($active)<x-icon :name="$icon" class="size-4"/>@endif
                    {{ $label }}
                    @if ($route === 'portal.support.index' && $replies)
                        <span class="num grid min-w-5 place-items-center rounded-full px-1.5 text-[11px] font-semibold text-white" style="background: var(--brand)">{{ $replies }}</span>
                    @endif
                </a>
            @endforeach
        </nav>

        <div class="ms-auto flex items-center gap-2">
            @php $unread = \App\Models\Announcement::for(auth()->user())->unreadBy(auth()->user())->count(); @endphp
            <a href="{{ route('portal.inbox') }}" class="icon-btn"
               aria-label="الإشعارات{{ $unread ? '، غير المقروء '.$unread : '' }}">
                <x-icon name="bell" class="size-[18px]"/>
                @if ($unread)
                    <span class="num absolute -end-1 -top-1 grid min-w-5 place-items-center rounded-full border border-white px-1 text-[11px] font-semibold text-white" style="background: var(--brand)">{{ $unread > 9 ? '9+' : $unread }}</span>
                @endif
            </a>
            <div class="hidden text-end sm:block">
                <div class="text-sm font-medium leading-tight">{{ $merchant->business_name }}</div>
                <div class="font-mono text-xs text-ink-500" dir="ltr">{{ $merchant->code }}</div>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="icon-btn" aria-label="خروج" title="خروج">
                    <x-icon name="logout" class="size-[18px] rtl:-scale-x-100"/>
                </button>
            </form>
        </div>
    </div>

    {{-- تنقّل الهاتف: التاجر يفتح هذا من جواله غالباً --}}
    <div class="overflow-x-auto px-4 pb-3 lg:hidden">
        <nav class="pill-nav w-max">
            @foreach ($portalNav as [$route, $label, $pattern, $icon, $short])
                @php $active = request()->routeIs($route); @endphp
                <a href="{{ route($route) }}" class="pill-nav-item px-3.5 {{ $active ? 'pill-nav-item-active' : '' }}"
                   @if ($active) aria-current="page" @endif>
                    {{ $short }}
                </a>
            @endforeach
        </nav>
    </div>
</header>

<main class="mx-auto max-w-screen-xl px-4 pb-12 pt-4">
    @if (session('success'))
        <div class="alert alert-ok mb-5" role="status">
            <x-icon name="check" class="size-5 shrink-0"/>
            <span class="font-medium">{{ session('success') }}</span>
        </div>
    @endif

    @if ($errors->any())
        <div class="alert alert-bad mb-5" role="alert">
            <x-icon name="alert" class="size-5 shrink-0"/>
            <div>
                <div class="font-semibold">راجع الحقول التالية:</div>
                <ul class="mt-1 list-disc ps-5">
                    @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        </div>
    @endif

    @yield('content')
</main>

</body>
</html>
