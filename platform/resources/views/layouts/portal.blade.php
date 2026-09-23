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
<body class="min-h-screen antialiased">

<header class="sticky top-0 z-30 border-b border-ink-200 bg-white/85 backdrop-blur">
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
                ['portal.shipments.import', 'رفع من ملف', 'portal.shipments.import*'],
                ['portal.pickups.index', 'طلبات الاستلام', 'portal.pickups.*'],
                ['portal.statement', 'حسابي', 'portal.statement'],
                ['portal.support.index', 'الدعم', 'portal.support.*'],
            ] as [$route, $label, $pattern])
                <a href="{{ route($route) }}"
                   class="rounded-lg px-3 py-1.5 font-medium {{ request()->routeIs($pattern) ? 'bg-ink-100 text-ink-900' : 'text-ink-600 hover:bg-ink-50' }}">
                    {{ $label }}
                    @if ($route === 'portal.support.index' && ($replies = \App\Models\Conversation::where('merchant_id', auth()->user()->merchant_id)->where('merchant_unread', true)->count()))
                        <span class="num ms-1 rounded-full px-1.5 text-[11px] font-bold text-white" style="background: var(--brand)">{{ $replies }}</span>
                    @endif
                </a>
            @endforeach
        </nav>

        <div class="ms-auto flex items-center gap-3">
            @php $unread = \App\Models\Announcement::for(auth()->user())->unreadBy(auth()->user())->count(); @endphp
            <a href="{{ route('portal.inbox') }}" class="relative rounded-lg p-2 text-ink-600 hover:bg-ink-100"
               aria-label="الإشعارات{{ $unread ? '، غير المقروء '.$unread : '' }}">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"/></svg>
                @if ($unread)
                    <span class="num absolute -end-0.5 -top-0.5 grid min-w-5 place-items-center rounded-full px-1 text-[11px] font-bold text-white" style="background: var(--brand)">{{ $unread > 9 ? '9+' : $unread }}</span>
                @endif
            </a>
            <div class="hidden text-left sm:block">
                <div class="text-sm font-semibold leading-tight">{{ $merchant->business_name }}</div>
                <div class="font-mono text-xs text-ink-500" dir="ltr">{{ $merchant->code }}</div>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="rounded-lg px-3 py-1.5 text-sm text-ink-600 hover:bg-ink-100">خروج</button>
            </form>
        </div>
    </div>

    {{-- تنقّل الهاتف: التاجر يفتح هذا من جواله غالباً --}}
    <nav class="flex gap-1 overflow-x-auto border-t border-ink-100 px-2 py-1.5 text-sm md:hidden">
        @foreach ([
            ['portal.dashboard', 'الرئيسية'],
            ['portal.shipments.index', 'شحناتي'],
            ['portal.shipments.create', 'جديدة'],
            ['portal.shipments.import', 'رفع'],
            ['portal.pickups.index', 'استلام'],
            ['portal.statement', 'حسابي'],
            ['portal.support.index', 'الدعم'],
        ] as [$route, $label])
            <a href="{{ route($route) }}"
               class="shrink-0 rounded-lg px-3 py-1.5 font-medium {{ request()->routeIs($route) ? 'bg-ink-100 text-ink-900' : 'text-ink-600' }}">
                {{ $label }}
            </a>
        @endforeach
    </nav>
</header>

<main class="mx-auto max-w-screen-xl px-4 py-6">
    @if (session('success'))
        <div class="mb-4 rounded-xl border border-ok-200 bg-ok-50 px-4 py-3 text-sm font-medium text-ok-700">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-4 rounded-xl border border-bad-200 bg-bad-50 px-4 py-3 text-sm text-bad-700">
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
