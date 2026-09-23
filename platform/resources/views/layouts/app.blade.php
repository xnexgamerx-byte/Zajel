<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'زاجل') — {{ $company->name ?? 'زاجل' }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Readex+Pro:wght@300;400;500;600;700&display=swap" rel="stylesheet">

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
    <div class="border-b border-ink-900 bg-sun text-ink-900">
        <div class="mx-auto flex max-w-screen-2xl flex-wrap items-center gap-3 px-4 py-2 text-sm">
            <x-icon name="eye" class="size-5 shrink-0"/>
            <span class="font-medium">
                أنت داخل نظام {{ $company->name }} من لوحة المنصّة — هذا الدخول مسجَّل في سجلّ الشركة.
            </span>
            <form method="POST" action="{{ route('impersonation.stop') }}" class="ms-auto">
                @csrf
                <button type="submit" class="btn-primary py-1.5">
                    عُد إلى لوحة المنصّة
                </button>
            </form>
        </div>
    </div>
@endif

<div class="flex min-h-screen">
    {{-- الشريط الجانبي: الأقسام مجمَّعة ومرئية كلّها بلا قوائم منسدلة --}}
    {{--
      على الشاشة الكبيرة لوحةٌ مُحاطة ثابتة؛ وعلى الهاتف درجٌ يُفتح بزرّ
      القائمة (data-open) وخلفه غطاءٌ يُغلقه. كان الدرج لا يظهر على الهاتف
      أصلاً: صنف hidden يبقى بعد أن يرفع السكربت سِمة hidden.
    --}}
    <aside class="peer fixed inset-y-0 start-0 z-40 w-[17.5rem] shrink-0 p-3 max-lg:hidden max-lg:data-open:block
                  lg:sticky lg:top-0 lg:h-screen"
           data-sidebar>
        <div class="flex h-full flex-col rounded-[28px] border border-ink-900 bg-white max-lg:shadow-2xl">
            <div class="flex items-center gap-3 px-4 pb-2 pt-4">
                <span class="grid size-11 shrink-0 place-items-center rounded-2xl border border-ink-900 text-lg font-bold text-white"
                      style="background: var(--brand)">ز</span>
                <div class="min-w-0 flex-1">
                    <div class="truncate text-[17px] font-semibold leading-tight">{{ $company->name }}</div>
                    <div class="mt-0.5 text-xs text-ink-500">نظام إدارة الشحنات</div>
                </div>
                <button type="button" data-sidebar-toggle class="icon-btn size-9 lg:hidden" aria-label="إغلاق القائمة">
                    <x-icon name="x" class="size-4"/>
                </button>
            </div>

            <nav class="flex-1 space-y-0.5 overflow-y-auto px-2.5 pb-4">
                @php
                    $groups = $staff ? [
                        ['العمليات', [
                            ['dashboard', 'لوحة اليوم', ['dashboard'], null, 'home'],
                            ['conversations.index', 'المحادثات', ['conversations.*'], 'support.reply', 'chat'],
                            ['announcements.index', 'إشعارات جماعية', ['announcements.*'], 'notify.send', 'megaphone'],
                            ['shipments.index', 'الشحنات', ['shipments.index', 'shipments.show'], 'shipments.view', 'box'],
                            ['shipments.create', 'شحنة جديدة', ['shipments.create'], 'shipments.create', 'plus'],
                            ['shipments.import', 'رفع من ملف', ['shipments.import*'], 'shipments.create', 'upload'],
                            ['pickups.index', 'طلبات الاستلام', ['pickups.*'], 'pickups.manage', 'clipboard'],
                            ['returns.incoming', 'استلام الراجع', ['returns.incoming'], 'returns.manage', 'undo'],
                            ['returns.sorting', 'فرز الراجع للفروع', ['returns.sorting'], 'returns.manage', 'sort'],
                            ['returns.outgoing', 'تسليم الراجع', ['returns.outgoing'], 'returns.manage', 'handover'],
                        ]],
                        ['النقل', [
                            ['bags.index', 'الأكياس', ['bags.*'], 'transport.manage', 'bag'],
                            ['courier-manifests.index', 'كشوف المناديب', ['courier-manifests.*'], 'transport.manage', 'clipboard'],
                            ['manifests.index', 'كشوف النقل', ['manifests.index', 'manifests.show'], 'transport.manage', 'truck'],
                            ['manifests.inbound', 'وارد المراكز', ['manifests.inbound'], 'transport.manage', 'inbound'],
                            ['manifests.archive', 'أرشيف الكشوف', ['manifests.archive', 'manifests.print'], 'transport.manage', 'archive'],
                        ]],
                        ['الأطراف', [
                            ['merchants.index', 'التجّار', ['merchants.*'], 'settings.people', 'store'],
                            ['couriers.index', 'المندوبون', ['couriers.index', 'couriers.show', 'couriers.create', 'couriers.edit'], 'settings.people', 'user'],
                            ['pickup-agents.index', 'مندوبو الاستلام', ['pickup-agents.*'], 'money.view', 'users'],
                        ]],
                        ['المال', [
                            ['couriers.cash', 'نقد المندوبين', ['couriers.cash'], 'money.view', 'cash'],
                            ['settlements.couriers.index', 'تسوية المندوبين', ['settlements.couriers.*'], 'money.view', 'exchange'],
                            ['settlements.merchants.index', 'تسوية التجّار', ['settlements.merchants.*'], 'money.view', 'exchange'],
                            ['cash.index', 'القاصة', ['cash.index'], 'money.cash', 'vault'],
                            ['expenses.index', 'المصروفات', ['expenses.index'], 'money.expenses', 'receipt'],
                            ['branch-accounts.index', 'محاسبة الفروع', ['branch-accounts.index'], 'money.view', 'building'],
                            ['branch-accounts.statement', 'كشف حساب الفرع', ['branch-accounts.statement*'], 'money.view', 'file'],
                            ['money.reconcile', 'مطابقة الدفتر', ['money.reconcile'], 'money.view', 'scale'],
                            ['branch-accounts.deposits', 'التأمينات', ['branch-accounts.deposits'], 'money.view', 'shield'],
                            ['pricing.index', 'التسعيرات', ['pricing.index', 'pricing.edit'], 'settings.pricing', 'tag'],
                        ]],
                        ['التقارير', [
                            ['reports.index', 'كل التقارير', ['reports.*'], 'reports.view', 'chart'],
                            ['control.duplicates', 'مشتبه بتكرارها', ['control.duplicates'], 'control.duplicates', 'copy'],
                            ['control.forced', 'واصل إجباري', ['control.forced'], 'control.force', 'bolt'],
                        ]],
                        ['الإعدادات', [
                            ['users.index', 'المستخدمون', ['users.*'], 'settings.people', 'users'],
                            ['permissions.index', 'الصلاحيات', ['permissions.*'], 'settings.permissions', 'lock'],
                            ['branches.index', 'الفروع', ['branches.*'], 'settings.branches', 'building'],
                            ['zones.index', 'المناطق', ['zones.*'], 'settings.zones', 'pin'],
                            ['settings.company', 'بيانات الشركة', ['settings.company*'], 'settings.company', 'sliders'],
                        ]],
                    ] : [
                        ['', [['shipments.index', 'الشحنات', ['shipments.*'], null, 'box']]],
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
                    @foreach ($links as [$route, $text, $patterns, $ability, $icon])
                        @php $active = collect($patterns)->contains(fn ($p) => request()->routeIs($p)); @endphp
                        <a href="{{ route($route) }}"
                           class="side-link {{ $active ? 'side-link-active' : '' }}"
                           @if ($active) aria-current="page" @endif>
                            <x-icon :name="$icon"/>
                            <span class="min-w-0 flex-1 truncate">{{ $text }}</span>
                            {{-- ما ينتظر ردّنا يُعَدّ على الرابط نفسه: لا يُكتشف بفتح الشاشة --}}
                            @if ($route === 'conversations.index' && ($waiting = \App\Models\Conversation::visibleTo(auth()->user())->where('status', 'open')->where('last_author', 'merchant')->count()))
                                <span class="num grid min-w-5 place-items-center rounded-full px-1.5 text-[11px] font-semibold text-white" style="background: var(--brand)">{{ $waiting }}</span>
                            @endif
                        </a>
                    @endforeach
                @endforeach
            </nav>

            <div class="m-2.5 flex items-center gap-3 rounded-2xl border border-ink-900 p-2.5">
                <span class="grid size-10 shrink-0 place-items-center rounded-full bg-ink-900 text-white">
                    <x-icon name="user" class="size-5"/>
                </span>
                <div class="min-w-0 flex-1">
                    <div class="truncate text-sm font-medium">{{ $user->name }}</div>
                    <div class="truncate text-xs text-ink-500">{{ $user->role->label() }}</div>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="icon-btn size-9" aria-label="تسجيل الخروج" title="تسجيل الخروج">
                        <x-icon name="logout" class="size-4 rtl:-scale-x-100"/>
                    </button>
                </form>
            </div>
        </div>
    </aside>

    {{-- غطاء الدرج على الهاتف: نقرةٌ خارجه تُغلقه --}}
    <button type="button" data-sidebar-toggle aria-label="إغلاق القائمة" tabindex="-1"
            class="fixed inset-0 z-30 hidden bg-ink-900/40 backdrop-blur-[2px] peer-data-open:block lg:peer-data-open:hidden"></button>

    <div class="min-w-0 flex-1">
        {{-- شريط علوي: البحث العام + فتح القائمة على الشاشات الصغيرة --}}
        {{-- رأس التصميم: بحثٌ حبّيّ، والفعل الأبرز بالأصفر --}}
        <header class="sticky top-0 z-20 bg-ink-50/85 backdrop-blur">
            <div class="flex h-[4.5rem] items-center gap-3 px-4 lg:px-6">
                <button type="button" data-sidebar-toggle class="icon-btn lg:hidden" aria-label="القائمة">
                    <x-icon name="menu" class="size-5"/>
                </button>

                @if ($staff)
                    <form method="GET" action="{{ route('shipments.index') }}" class="relative max-w-md flex-1">
                        <x-icon name="search" class="pointer-events-none absolute start-4 top-1/2 size-4 -translate-y-1/2 text-ink-500"/>
                        <input name="q" value="{{ request('q') }}" class="field-input ps-10"
                               placeholder="ابحث برقم الوصل أو هاتف الزبون…">
                    </form>

                    <a href="{{ route('shipments.create') }}" class="btn-accent ms-auto shrink-0">
                        <x-icon name="plus" class="size-4"/>
                        شحنة
                    </a>
                @else
                    <span class="ms-auto text-sm font-medium">{{ $company->name }}</span>
                @endif
            </div>
        </header>

        <main class="mx-auto max-w-screen-2xl px-4 pb-12 pt-2 lg:px-6">
            @if (session('success'))
                <div class="alert alert-ok mb-5" role="status">
                    <x-icon name="check" class="size-5 shrink-0"/>
                    <span class="font-medium">{{ session('success') }}</span>
                </div>
            @endif

            @if ($errors->any() && ! request()->routeIs('login'))
                <div class="alert alert-bad mb-5" role="alert">
                    <x-icon name="alert" class="size-5 shrink-0"/>
                    <div>
                        <div class="font-semibold">تعذّر الحفظ:</div>
                        <ul class="mt-1 list-disc ps-5">
                            @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                        </ul>
                    </div>
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
        <div class="alert alert-bad mb-4" role="alert">
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
