<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'وهج العراق') — {{ $company->name ?? 'وهج العراق' }}</title>

    @include('partials.fonts')

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @isset($company)
        @include('partials.company-style')
    @endisset
</head>
@php
    $user = auth()->user();
    $staff = (bool) $user?->isStaff();
@endphp
<body class="min-h-screen antialiased {{ $staff ? 'has-rail' : '' }}">

@auth
@php
    $impersonating = session()->has(\App\Actions\Platform\ImpersonateCompany::SESSION_KEY);
    $menus = $staff ? \App\Support\StaffNavigation::for($user, request()) : [];
    $canCreate = $staff && $user->can('shipments.create');
    // حرفا الاسم في دائرة الحساب: «أحمد كريم» ← «أ ك»
    $initials = collect(preg_split('/\s+/u', trim($user->name)))->filter()->take(2)
        ->map(fn ($word) => mb_substr($word, 0, 1))->implode(' ');
    // الزرّ العائم على الهاتف، إلا في شاشات إدخال الشحنات نفسها
    $fab = $canCreate && ! request()->routeIs('shipments.create', 'shipments.quick*', 'shipments.import*', 'shipments.waybill');
@endphp

@if ($staff)
{{--
  شريط «نبض» الجانبي (navigation rail): من ١٠٢٤ بكسل عمودٌ ثابتٌ في جهة البداية —
  شعار الشركة، ثم زرّ «شحنة جديدة» العائم، ثم القوائم بما تفعله كل شاشة
  (StaffNavigation) بترتيب الشركة. القائمة ذات الرابط الواحد رابطٌ مباشر، والأخرى
  تنفتح بطاقةً بجانبه. وعلى الهاتف هو نفسه درجٌ يفتحه زرّ القائمة في الرأس.
--}}
<nav id="main-nav" aria-label="القائمة الرئيسية" class="rail" data-drawer>
    <div class="rail-head">
        <a href="{{ ! $company->logo_path && auth()->user()->can('settings.company') ? route('settings.company').'#logo' : route('dashboard') }}" class="rail-brand" title="{{ $company->name }}">
            <x-brand-tile :company="$company" />
            <span class="min-w-0 lg:hidden">
                <span class="block truncate font-heading text-base leading-tight font-extrabold text-aeblack-950">{{ $company->name }}</span>
                <span class="block text-xs text-ink-500">نظام إدارة الشحنات</span>
            </span>
        </a>
        <button type="button" class="icon-btn lg:hidden" data-drawer-close aria-label="أغلق القائمة">
            <x-icon name="x" class="size-6"/>
        </button>
    </div>

    @if ($canCreate)
        <a href="{{ route('shipments.create') }}" class="rail-fab" aria-label="شحنة جديدة" title="شحنة جديدة">
            <x-icon name="plus" class="size-7"/>
        </a>
    @endif

    <ul class="rail-menu">
        @foreach ($menus as $i => $menu)
            @if ($menu['url'])
                <li>
                    <a href="{{ $menu['url'] }}" class="nav-item {{ $menu['active'] ? 'nav-item-active' : '' }}"
                       @if ($menu['active']) aria-current="page" @endif>
                        <span class="nav-pill">
                            <x-icon :name="$menu['icon']" class="size-6"/>
                            @if ($menu['badge'])
                                <span class="nav-badge">{{ $menu['badge'] }}</span>
                            @endif
                        </span>
                        <span class="nav-label">{{ $menu['label'] }}</span>
                    </a>
                </li>
                @continue
            @endif
            <li data-menu>
                <button type="button" class="nav-item {{ $menu['active'] ? 'nav-item-active' : '' }}"
                        data-menu-toggle aria-expanded="false" aria-controls="menu-{{ $i }}">
                    <span class="nav-pill">
                        <x-icon :name="$menu['icon']" class="size-6"/>
                        @if ($menu['badge'])
                            <span class="nav-badge">{{ $menu['badge'] }}</span>
                        @endif
                    </span>
                    <span class="nav-label">{{ $menu['label'] }}</span>
                    <x-icon name="chevron-down" class="nav-item-caret"/>
                </button>

                <div id="menu-{{ $i }}" class="submenu {{ count($menu['links']) > 6 ? 'submenu-wide' : '' }}"
                     data-menu-panel hidden>
                    <div class="submenu-title">{{ $menu['label'] }}</div>
                    <ul class="submenu-list">
                        @foreach ($menu['links'] as $link)
                            <li>
                                <a href="{{ $link['url'] }}"
                                   class="submenu-link {{ $link['active'] ? 'submenu-link-active' : '' }}"
                                   @if ($link['active']) aria-current="page" @endif>
                                    <span>{{ $link['label'] }}</span>
                                    @if ($link['badge'])
                                        <span class="nav-badge">{{ $link['badge'] }}</span>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </li>
        @endforeach
    </ul>

    {{-- الحساب والخروج في أسفل الدرج على الهاتف؛ على الشاشة الواسعة هما في الرأس --}}
    <div class="rail-foot">
        <span class="avatar" aria-hidden="true">{{ $initials }}</span>
        <span class="min-w-0 flex-1">
            <span class="block truncate text-sm font-bold text-aeblack-900">{{ $user->name }}</span>
            <span class="block truncate text-xs text-ink-500">{{ $user->role->label() }}</span>
        </span>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="icon-btn" aria-label="تسجيل الخروج" title="تسجيل الخروج">
                <x-icon name="logout" class="size-5 rtl:-scale-x-100"/>
            </button>
        </form>
    </div>
</nav>
<div class="drawer-scrim" data-drawer-close></div>
@endif

<div class="app-frame">
@if ($impersonating)
    {{-- شريط لا يُخطأ: من يعمل داخل نظام شركة يجب أن يعرف أنه ليس نفسه --}}
    <div class="bg-warn-soft text-warn-deep">
        <div class="shell flex flex-wrap items-center gap-3 py-2 text-sm">
            <x-icon name="eye" class="size-5 shrink-0"/>
            <span class="font-semibold">
                أنت داخل نظام {{ $company->name }} من لوحة المنصّة — هذا الدخول مسجَّل في سجلّ الشركة.
            </span>
            <form method="POST" action="{{ route('impersonation.stop') }}" class="ms-auto">
                @csrf
                <button type="submit" class="btn-primary py-1">
                    عُد إلى لوحة المنصّة
                </button>
            </form>
        </div>
    </div>
@endif

{{--
  الرأس على أرضية الصفحة: البحث عن شحنة حبّةٌ بيضاء عريضة، ثم «صندوقي» والحساب.
  وعلى الهاتف زرّ الدرج وشعار الشركة، والبحث في سطرٍ تحتهما.
--}}
<header class="app-bar">
    <div class="shell flex flex-wrap items-center gap-x-3 gap-y-3 py-3 lg:py-4">
        @if ($staff)
            <button type="button" class="icon-btn lg:hidden" data-drawer-toggle aria-controls="main-nav" aria-expanded="false"
                    aria-label="القائمة">
                <x-icon name="menu" class="size-6"/>
            </button>
        @endif

        <a href="{{ ! $company->logo_path && auth()->user()->can('settings.company') ? route('settings.company').'#logo' : route($staff ? 'dashboard' : 'shipments.index') }}" class="flex min-w-0 items-center gap-3 max-lg:flex-1 lg:max-w-56">
            <x-brand-tile :company="$company" :size="'size-10 text-base '.($staff ? 'lg:hidden' : '')" />
            <span class="min-w-0">
                <span class="block truncate font-heading text-lg leading-tight font-extrabold text-aeblack-950">{{ $company->name }}</span>
                <span class="block truncate text-xs text-ink-500">نظام إدارة الشحنات</span>
            </span>
        </a>

        @if ($staff)
            <form method="GET" action="{{ route('shipments.index') }}" role="search"
                  class="app-search order-last basis-full md:order-none md:max-w-xl md:flex-1 md:basis-auto">
                <label for="global-search" class="sr-only">ابحث عن شحنة</label>
                <x-icon name="search" class="app-search-icon"/>
                <input id="global-search" name="q" value="{{ request()->routeIs('shipments.index') ? request('q') : '' }}" class="app-search-input" enterkeyhint="search"
                       placeholder="ابحث برقم الوصل أو هاتف الزبون…">
            </form>
        @endif

        <div class="ms-auto flex items-center gap-2">
            {{-- «صندوقي»: ما بيد الموظّف الآن، لمن له صندوق --}}
            @if ($staff && ($myBox = \App\Models\CashBox::where('user_id', $user->id)->first(['id', 'balance'])))
                <a href="{{ route('cash.mine') }}" class="app-chip" title="صندوقي">
                    <x-icon name="cash" class="size-[18px] text-primary-700"/>
                    <span class="max-sm:sr-only">صندوقي</span> <span class="num">{{ number_format($myBox->balance) }}</span>
                </a>
            @endif

            <span class="avatar" title="{{ $user->name }}" aria-hidden="true">{{ $initials }}</span>
            <span class="hidden min-w-0 xl:block">
                <span class="block max-w-40 truncate text-sm font-bold text-aeblack-900">{{ $user->name }}</span>
                <span class="block truncate text-xs text-ink-500">{{ $user->role->label() }}</span>
            </span>
            <form method="POST" action="{{ route('logout') }}" class="{{ $staff ? 'max-lg:hidden' : '' }}">
                @csrf
                <button type="submit" class="icon-btn bg-white" aria-label="تسجيل الخروج" title="تسجيل الخروج">
                    <x-icon name="logout" class="size-5 rtl:-scale-x-100"/>
                </button>
            </form>
        </div>
    </div>

    @unless ($staff)
        @php $here = request()->routeIs('shipments.*'); @endphp
        <nav class="nav-strip" aria-label="القائمة الرئيسية">
            <div class="shell tab-nav">
                <a href="{{ route('shipments.index') }}" class="tab-link {{ $here ? 'tab-link-active' : '' }}"
                   @if ($here) aria-current="page" @endif>
                    <x-icon name="box" class="size-5"/>
                    الشحنات
                </a>
            </div>
        </nav>
    @endunless
</header>

<main class="shell pt-2 pb-12 lg:pt-3 {{ $fab ? 'max-lg:pb-28' : '' }}">
    {{-- ما على الشركة للمنصّة، لمن يدفع عنها وحده (docs/plan/36). وفي صفحة الفواتير تفصيله فيها --}}
    @if ($staff && isset($company) && ! request()->routeIs('billing*') && $user->can('settings.company') && ! $user->isBranchLimited())
        <x-billing-banner />
    @endif

    @if (session('success'))
        <div class="alert alert-ok mb-5" role="status">
            <x-icon name="check" class="size-5 shrink-0"/>
            <span class="font-semibold">{{ session('success') }}</span>
            {{-- ما سُلّم بيدٍ يُوقَّع على ورقته: رابط طباعتها مع الرسالة نفسها --}}
            @if (session('print'))
                <a href="{{ session('print') }}" target="_blank" class="btn-ghost ms-auto bg-white/70 py-1">
                    <x-icon name="printer" class="size-5"/> اطبع الإيصال
                </a>
            @endif
        </div>
    @endif

    @if ($errors->any() && ! request()->routeIs('login'))
        <div class="alert alert-bad mb-5" role="alert">
            <x-icon name="alert" class="size-5 shrink-0"/>
            <div>
                <div class="font-bold">تعذّر الحفظ:</div>
                <ul class="mt-1 list-disc ps-5">
                    @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        </div>
    @endif

    @yield('content')
</main>
</div>

@if ($fab)
    {{-- على الهاتف: «شحنة جديدة» زرٌّ عائمٌ ممتدّ في متناول الإبهام --}}
    <a href="{{ route('shipments.create') }}" class="fab">
        <x-icon name="plus" class="size-6"/>
        شحنة جديدة
    </a>
@endif
@endauth

@guest
<div class="ds-header" aria-hidden="true"></div>
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


</body>
</html>
