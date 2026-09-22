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
<body class="min-h-screen antialiased">

@auth
@php
    $user = auth()->user();
    $staff = $user->isStaff();
    $impersonating = session()->has(\App\Actions\Platform\ImpersonateCompany::SESSION_KEY);
@endphp

@if ($impersonating)
    {{-- شريط لا يُخطأ: من يعمل داخل نظام شركة يجب أن يعرف أنه ليس نفسه --}}
    <div class="bg-warn-700 text-white">
        <div class="mx-auto flex max-w-screen-2xl flex-wrap items-center gap-3 px-4 py-2 text-sm">
            <span class="font-semibold">
                أنت داخل نظام {{ $company->name }} من لوحة المنصّة — هذا الدخول مسجَّل في سجلّ الشركة.
            </span>
            <form method="POST" action="{{ route('impersonation.stop') }}" class="ms-auto">
                @csrf
                <button type="submit" class="rounded-lg bg-white/20 px-3 py-1 font-semibold hover:bg-white/30">
                    عُد إلى لوحة المنصّة
                </button>
            </form>
        </div>
    </div>
@endif

<div class="flex min-h-screen">
    {{-- الشريط الجانبي: الأقسام مجمَّعة ومرئية كلّها بلا قوائم منسدلة --}}
    <aside class="fixed inset-y-0 z-40 hidden w-60 shrink-0 border-s border-ink-200 bg-ink-50 lg:static lg:block"
           data-sidebar hidden>
        <div class="flex h-full flex-col">
            <div class="flex h-16 items-center gap-2.5 px-4">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl text-sm font-black text-white"
                      style="background: var(--brand)">ز</span>
                <div class="min-w-0">
                    <div class="truncate text-sm font-bold">{{ $company->name }}</div>
                    <div class="text-[11px] text-ink-500">نظام إدارة الشحنات</div>
                </div>
            </div>

            <nav class="flex-1 overflow-y-auto px-2 pb-4">
                @php
                    $groups = $staff ? [
                        ['العمليات', [
                            ['dashboard', 'لوحة اليوم', ['dashboard'], null],
                            ['shipments.index', 'الشحنات', ['shipments.index', 'shipments.show'], 'shipments.view'],
                            ['shipments.create', 'شحنة جديدة', ['shipments.create'], 'shipments.create'],
                            ['shipments.import', 'رفع من ملف', ['shipments.import*'], 'shipments.create'],
                            ['pickups.index', 'طلبات الاستلام', ['pickups.*'], 'pickups.manage'],
                            ['returns.incoming', 'استلام الراجع', ['returns.incoming'], 'returns.manage'],
                            ['returns.outgoing', 'تسليم الراجع', ['returns.outgoing'], 'returns.manage'],
                        ]],
                        ['النقل', [
                            ['bags.index', 'الأكياس', ['bags.*'], 'transport.manage'],
                            ['manifests.index', 'كشوف النقل', ['manifests.index', 'manifests.show'], 'transport.manage'],
                            ['manifests.inbound', 'وارد المراكز', ['manifests.inbound'], 'transport.manage'],
                        ]],
                        ['الأطراف', [
                            ['merchants.index', 'التجّار', ['merchants.*'], 'settings.people'],
                            ['couriers.index', 'المندوبون', ['couriers.index', 'couriers.show', 'couriers.create', 'couriers.edit'], 'settings.people'],
                            ['pickup-agents.index', 'مندوبو الاستلام', ['pickup-agents.*'], 'money.view'],
                        ]],
                        ['المال', [
                            ['couriers.cash', 'نقد المندوبين', ['couriers.cash'], 'money.view'],
                            ['settlements.couriers.index', 'تسوية المندوبين', ['settlements.couriers.*'], 'money.view'],
                            ['settlements.merchants.index', 'تسوية التجّار', ['settlements.merchants.*'], 'money.view'],
                            ['cash.index', 'القاصة', ['cash.index'], 'money.cash'],
                            ['expenses.index', 'المصروفات', ['expenses.index'], 'money.expenses'],
                            ['branch-accounts.index', 'محاسبة الفروع', ['branch-accounts.index'], 'money.view'],
                            ['branch-accounts.deposits', 'التأمينات', ['branch-accounts.deposits'], 'money.view'],
                            ['pricing.index', 'التسعيرات', ['pricing.index', 'pricing.edit'], 'settings.pricing'],
                        ]],
                        ['التقارير', [
                            ['reports.index', 'كل التقارير', ['reports.*'], 'reports.view'],
                            ['control.duplicates', 'مشتبه بتكرارها', ['control.duplicates'], 'control.duplicates'],
                            ['control.forced', 'واصل إجباري', ['control.forced'], 'control.force'],
                        ]],
                        ['الإعدادات', [
                            ['users.index', 'المستخدمون', ['users.*'], 'settings.people'],
                            ['permissions.index', 'الصلاحيات', ['permissions.*'], 'settings.permissions'],
                            ['branches.index', 'الفروع', ['branches.*'], 'settings.branches'],
                            ['zones.index', 'المناطق', ['zones.*'], 'settings.zones'],
                        ]],
                    ] : [
                        ['', [['shipments.index', 'الشحنات', ['shipments.*'], null]]],
                    ];
                @endphp

                @php
                    /*
                    | الرابط لا يظهر لمن لا يستطيع فتحه: قائمة تُفضي إلى
                    | 403 أسوأ من قائمة قصيرة، وهي تُطلع الموظّف على ما
                    | لا يخصّه.
                    */
                    $groups = collect($groups)
                        ->map(fn ($group) => [$group[0], collect($group[1])
                            ->filter(fn ($link) => ($link[3] ?? null) === null || auth()->user()->can($link[3]))
                            ->values()->all()])
                        ->filter(fn ($group) => count($group[1]) > 0)
                        ->values()->all();
                @endphp

                @foreach ($groups as [$label, $links])
                    @if ($label)
                        <div class="side-group">{{ $label }}</div>
                    @endif
                    @foreach ($links as [$route, $text, $patterns, $ability])
                        @php $active = collect($patterns)->contains(fn ($p) => request()->routeIs($p)); @endphp
                        <a href="{{ route($route) }}"
                           class="side-link {{ $active ? 'side-link-active' : '' }}">
                            {{ $text }}
                        </a>
                    @endforeach
                @endforeach
            </nav>

            <div class="border-t border-ink-200 p-3">
                <div class="mb-2 px-1">
                    <div class="truncate text-sm font-semibold">{{ $user->name }}</div>
                    <div class="text-xs text-ink-500">{{ $user->role->label() }}</div>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="side-link w-full">تسجيل الخروج</button>
                </form>
            </div>
        </div>
    </aside>

    <div class="min-w-0 flex-1">
        {{-- شريط علوي: البحث العام + فتح القائمة على الشاشات الصغيرة --}}
        <header class="sticky top-0 z-30 border-b border-ink-200 bg-white/85 backdrop-blur">
            <div class="flex h-16 items-center gap-3 px-4">
                <button type="button" data-sidebar-toggle
                        class="btn-ghost px-3 py-2 lg:hidden" aria-label="القائمة">☰</button>

                @if ($staff)
                    <form method="GET" action="{{ route('shipments.index') }}" class="max-w-md flex-1">
                        <input name="q" value="{{ request('q') }}" class="field-input"
                               placeholder="ابحث برقم الوصل أو هاتف الزبون…">
                    </form>

                    <a href="{{ route('shipments.create') }}" class="btn-primary ms-auto shrink-0">
                        + شحنة
                    </a>
                @else
                    <span class="ms-auto text-sm font-semibold">{{ $company->name }}</span>
                @endif
            </div>
        </header>

        <main class="mx-auto max-w-screen-2xl px-4 py-6">
            @if (session('success'))
                <div class="mb-4 rounded-xl border border-ok-200 bg-ok-50 px-4 py-3 text-sm font-medium text-ok-700">
                    {{ session('success') }}
                </div>
            @endif

            @if ($errors->any() && ! request()->routeIs('login'))
                <div class="mb-4 rounded-xl border border-bad-200 bg-bad-50 px-4 py-3 text-sm text-bad-700">
                    <div class="font-semibold">تعذّر الحفظ:</div>
                    <ul class="mt-1 list-disc ps-5">
                        @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                    </ul>
                </div>
            @endif

            @yield('content')
        </main>
    </div>
</div>
@endauth

@guest
<main class="mx-auto max-w-screen-sm px-4 py-10">
    @if ($errors->any() && ! request()->routeIs('login'))
        <div class="mb-4 rounded-xl border border-bad-200 bg-bad-50 px-4 py-3 text-sm text-bad-700">
            <ul class="list-disc ps-5">
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    @yield('content')
</main>
@endguest


{{--
  كشف رقم واحد في كل مرّة: أي كشف جديد يُخفي سابقه، ويعود الرقم مخفيّاً
  بعد نصف دقيقة. الحماية هنا ليست تقنية — الرقم في الصفحة على أي حال —
  بل جعل النسخ الجَماعيّ عملاً مقصوداً يُرى.
--}}
<script>
    let revealedPhone = null;
    let revealTimer = null;

    function revealPhone(button) {
        const cell = button.closest('[data-phone]').querySelector('[data-phone-value]');

        if (revealedPhone && revealedPhone !== cell) {
            revealedPhone.textContent = revealedPhone.dataset.masked;
        }

        clearTimeout(revealTimer);

        if (cell.textContent === cell.dataset.real) {
            cell.textContent = cell.dataset.masked;
            revealedPhone = null;

            return;
        }

        cell.textContent = cell.dataset.real;
        revealedPhone = cell;
        revealTimer = setTimeout(() => {
            cell.textContent = cell.dataset.masked;
            revealedPhone = null;
        }, 30000);
    }
</script>

</body>
</html>
