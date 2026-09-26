<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'بوابة التاجر') — {{ $company->name }}</title>

    @include('partials.fonts')

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- البوابة تحمل علامة شركة التوصيل لا علامة المنصّة: التاجر
         يتعامل مع "الزاجل" لا مع "وهج" المنصّة. --}}
    <style>:root { --company: {{ $company->primary_color }}; }</style>
</head>
<body class="min-h-screen antialiased">

{{-- رأس «وهج»: الشعار والجرس والحساب على خلفية الصفحة، ثم تبويبات البوابة حبّاتٍ --}}
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
<header class="ds-header">
    <div class="mx-auto flex max-w-screen-xl items-center gap-3 px-4 py-3">
        <a href="{{ route('portal.dashboard') }}" class="flex min-w-0 items-center gap-3">
            <span class="brand-tile">{{ $company->initial() }}</span>
            <span class="min-w-0">
                <span class="block truncate font-heading text-lg leading-tight font-bold text-aeblack-900">{{ $company->name }}</span>
                <span class="block text-xs text-ink-500">بوابة التاجر</span>
            </span>
        </a>

        <div class="ms-auto flex items-center gap-2">
            @php $unread = \App\Models\Announcement::for(auth()->user())->unreadBy(auth()->user())->count(); @endphp
            <a href="{{ route('portal.inbox') }}" class="icon-btn bg-white"
               aria-label="الإشعارات{{ $unread ? '، غير المقروء '.$unread : '' }}">
                <x-icon name="bell" class="size-5"/>
                @if ($unread)
                    <span class="nav-badge absolute -end-1 -top-1">{{ $unread > 9 ? '9+' : $unread }}</span>
                @endif
            </a>
            <div class="hidden border-s border-ink-200 ps-3 text-end sm:block">
                <div class="text-sm leading-tight font-semibold text-aeblack-900">{{ $merchant->business_name }}</div>
                <div class="font-mono text-xs text-ink-500" dir="ltr">{{ $merchant->code }}</div>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="icon-btn bg-white" aria-label="خروج" title="خروج">
                    <x-icon name="logout" class="size-5 rtl:-scale-x-100"/>
                </button>
            </form>
        </div>
    </div>

    {{-- التاجر يفتح هذا من جواله غالباً: التبويبات تنزلق أفقياً، وأسماؤها أقصر على الهاتف --}}
    <nav class="nav-strip" aria-label="أقسام البوابة">
        <div class="tab-nav mx-auto max-w-screen-xl px-4">
            @foreach ($portalNav as [$route, $label, $pattern, $icon, $short])
                @php $active = request()->routeIs($pattern); @endphp
                <a href="{{ route($route) }}" class="tab-link {{ $active ? 'tab-link-active' : '' }}"
                   @if ($active) aria-current="page" @endif>
                    <x-icon :name="$icon" class="size-5 max-sm:hidden"/>
                    <span class="lg:hidden">{{ $short }}</span>
                    <span class="max-lg:hidden">{{ $label }}</span>
                    @if ($route === 'portal.support.index' && $replies)
                        <span class="nav-badge">{{ $replies }}</span>
                    @endif
                </a>
            @endforeach
        </div>
    </nav>
</header>

<main class="mx-auto max-w-screen-xl px-4 pt-6 pb-12">
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
