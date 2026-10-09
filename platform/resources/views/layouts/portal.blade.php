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
    @include('partials.company-style')
</head>
<body class="min-h-screen antialiased has-rail has-dock">

@php
    $portalNav = [
        ['portal.dashboard', 'الرئيسية', 'portal.dashboard', 'home', 'الرئيسية'],
        ['portal.shipments.index', 'شحناتي', 'portal.shipments.index', 'box', 'شحناتي'],
        ['portal.shipments.create', 'شحنة جديدة', 'portal.shipments.create', 'plus', 'جديدة'],
        ['portal.shipments.import', 'رفع ملف Excel', 'portal.shipments.import*', 'upload', 'رفع'],
        ['portal.waybills.index', 'وصولات للطباعة', 'portal.waybills.*', 'printer', 'وصولات'],
        ['portal.pickups.index', 'طلبات الاستلام', 'portal.pickups.*', 'clipboard', 'استلام'],
        ['portal.statement', 'حسابي', 'portal.statement', 'wallet', 'حسابي'],
        ['portal.requests.index', 'طلباتي', 'portal.requests.*', 'card', 'طلباتي'],
        ['portal.support.index', 'الدعم', 'portal.support.*', 'chat', 'الدعم'],
    ];
    // «للمعالجة» لمن سُمح له أن يعالج محاولاته الفاشلة بنفسه، وبعدد ما ينتظره
    $toProcess = 0;
    if ($merchant->can_process) {
        array_splice($portalNav, 2, 0, [['portal.processing.index', 'للمعالجة', 'portal.processing.*', 'alert', 'للمعالجة']]);
        $toProcess = \App\Models\Shipment::where('merchant_id', $merchant->id)
            ->where('status', \App\Enums\ShipmentStatus::FailedAttempt->value)->count();
    }
    // ميزةٌ أغلقتها المنصّة لهذه الشركة لا تبويب لها (docs/plan/35)
    $portalNav = array_values(array_filter($portalNav, fn (array $item) => \App\Support\FeatureGate::allowsRoute($item[0])));
    $replies = \App\Support\FeatureGate::enabled('conversations')
        ? \App\Models\Conversation::where('merchant_id', auth()->user()->merchant_id)->where('merchant_unread', true)->count()
        : 0;
@endphp
@php
    // حرفا اسم المتجر في دائرة الحساب: «متجر النخيل» ← «م ن»
    $initials = collect(preg_split('/\s+/u', trim($merchant->business_name)))->filter()->take(2)
        ->map(fn ($word) => mb_substr($word, 0, 1))->implode(' ');
    $unread = \App\Models\Announcement::for(auth()->user())->unreadBy(auth()->user())->count();
    $canCreate = \App\Support\FeatureGate::allowsRoute('portal.shipments.create');
    // الزرّ العائم على الهاتف، إلا في شاشات إدخال الشحنات نفسها
    $fab = $canCreate && ! request()->routeIs('portal.shipments.create', 'portal.shipments.import*', 'portal.waybills.*');
    // شريط الهاتف السفليّ: أربع وجهاتٍ يومية، والباقي في «المزيد» (الدرج)
    $dock = array_values(array_filter([
        ['portal.dashboard', 'الرئيسية', 'portal.dashboard', 'home'],
        ['portal.shipments.index', 'شحناتي', 'portal.shipments.index', 'box'],
        ['portal.statement', 'حسابي', 'portal.statement', 'wallet'],
        ['portal.support.index', 'الدعم', 'portal.support.*', 'chat'],
    ], fn (array $item) => \App\Support\FeatureGate::allowsRoute($item[0])));
@endphp

{{--
  «نبض» في بوابة التاجر (docs/plan/40): على الشاشة الواسعة الشريط الجانبي نفسه الذي
  يراه الموظّف — الشعار، وزرّ «شحنة جديدة»، والأقسام بأيقوناتها. وعلى الهاتف — حيث
  يفتحها التاجر غالباً — شريطٌ سفليّ بأربع وجهات و«المزيد» يفتح الدرج، وزرٌّ عائم.
--}}
<nav id="main-nav" aria-label="أقسام البوابة" class="rail" data-drawer>
    <div class="rail-head">
        <a href="{{ route('portal.dashboard') }}" class="rail-brand" title="{{ $company->name }}">
            <span class="brand-tile">{{ $company->initial() }}</span>
            <span class="min-w-0 lg:hidden">
                <span class="block truncate font-heading text-base leading-tight font-extrabold text-aeblack-950">{{ $company->name }}</span>
                <span class="block text-xs text-ink-500">بوابة التاجر</span>
            </span>
        </a>
        <button type="button" class="icon-btn lg:hidden" data-drawer-close aria-label="أغلق القائمة">
            <x-icon name="x" class="size-6"/>
        </button>
    </div>

    @if ($canCreate)
        <a href="{{ route('portal.shipments.create') }}" class="rail-fab" aria-label="شحنة جديدة" title="شحنة جديدة">
            <x-icon name="plus" class="size-7"/>
        </a>
    @endif

    <ul class="rail-menu">
        @foreach ($portalNav as [$route, $label, $pattern, $icon, $short])
            @php $active = request()->routeIs($pattern); @endphp
            <li>
                <a href="{{ route($route) }}" class="nav-item {{ $active ? 'nav-item-active' : '' }}"
                   @if ($active) aria-current="page" @endif title="{{ $label }}">
                    <span class="nav-pill">
                        <x-icon :name="$icon" class="size-6"/>
                        @if ($route === 'portal.support.index' && $replies)
                            <span class="nav-badge">{{ $replies }}</span>
                        @endif
                        @if ($route === 'portal.processing.index' && $toProcess)
                            <span class="nav-badge">{{ $toProcess > 99 ? '99+' : $toProcess }}</span>
                        @endif
                    </span>
                    {{-- في الدرج الاسم كاملاً، وفي الشريط الضيّق قصيراً --}}
                    <span class="nav-label"><span class="lg:hidden">{{ $label }}</span><span class="max-lg:hidden">{{ $short }}</span></span>
                </a>
            </li>
        @endforeach
    </ul>

    <div class="rail-foot">
        <span class="avatar" aria-hidden="true">{{ $initials }}</span>
        <span class="min-w-0 flex-1">
            <span class="block truncate text-sm font-bold text-aeblack-900">{{ $merchant->business_name }}</span>
            <span class="block truncate font-mono text-xs text-ink-500" dir="ltr">{{ $merchant->code }}</span>
        </span>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="icon-btn" aria-label="خروج" title="خروج">
                <x-icon name="logout" class="size-5 rtl:-scale-x-100"/>
            </button>
        </form>
    </div>
</nav>
<div class="drawer-scrim" data-drawer-close></div>

<div class="app-frame">
<header class="app-bar">
    <div class="shell flex flex-wrap items-center gap-x-3 gap-y-3 py-3 lg:py-4">
        <a href="{{ route('portal.dashboard') }}" class="flex min-w-0 items-center gap-3 max-lg:flex-1 lg:max-w-56">
            <span class="brand-tile size-10 text-base lg:hidden">{{ $company->initial() }}</span>
            <span class="min-w-0">
                <span class="block truncate font-heading text-lg leading-tight font-extrabold text-aeblack-950">{{ $company->name }}</span>
                <span class="block truncate text-xs text-ink-500">بوابة التاجر</span>
            </span>
        </a>

        {{-- ابحث في شحناتي: برقم الوصل أو هاتف الزبون أو اسمه --}}
        <form method="GET" action="{{ route('portal.shipments.index') }}" role="search"
              class="app-search order-last basis-full md:order-none md:max-w-xl md:flex-1 md:basis-auto">
            <label for="portal-search" class="sr-only">ابحث في شحناتي</label>
            <x-icon name="search" class="app-search-icon"/>
            <input id="portal-search" name="q" value="{{ request()->routeIs('portal.shipments.index') ? request('q') : '' }}"
                   class="app-search-input" enterkeyhint="search" placeholder="ابحث برقم الوصل أو هاتف الزبون…">
        </form>

        <div class="ms-auto flex items-center gap-2">
            <a href="{{ route('portal.inbox') }}" class="icon-btn relative bg-white"
               aria-label="الإشعارات{{ $unread ? '، غير المقروء '.$unread : '' }}">
                <x-icon name="bell" class="size-5"/>
                @if ($unread)
                    <span class="nav-badge absolute -end-1 -top-1">{{ $unread > 9 ? '9+' : $unread }}</span>
                @endif
            </a>
            <span class="avatar max-lg:hidden" title="{{ $merchant->business_name }}" aria-hidden="true">{{ $initials }}</span>
            <span class="hidden min-w-0 xl:block">
                <span class="block max-w-44 truncate text-sm leading-tight font-bold text-aeblack-900">{{ $merchant->business_name }}</span>
                <span class="block font-mono text-xs text-ink-500" dir="ltr">{{ $merchant->code }}</span>
            </span>
            <form method="POST" action="{{ route('logout') }}" class="max-lg:hidden">
                @csrf
                <button type="submit" class="icon-btn bg-white" aria-label="خروج" title="خروج">
                    <x-icon name="logout" class="size-5 rtl:-scale-x-100"/>
                </button>
            </form>
        </div>
    </div>
</header>

<main class="shell pt-2 pb-12 max-lg:pb-40 lg:pt-3">
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
</div>

@if ($fab)
    {{-- على الهاتف: «شحنة جديدة» زرٌّ عائمٌ فوق الشريط السفليّ، في متناول الإبهام --}}
    <a href="{{ route('portal.shipments.create') }}" class="fab fab-over-dock">
        <x-icon name="plus" class="size-6"/>
        شحنة جديدة
    </a>
@endif

<nav class="bottom-nav" aria-label="التنقّل السريع">
    @foreach ($dock as [$route, $label, $pattern, $icon])
        @php $active = request()->routeIs($pattern); @endphp
        <a href="{{ route($route) }}" class="bnav-item {{ $active ? 'bnav-item-active' : '' }}" @if ($active) aria-current="page" @endif>
            <span class="bnav-pill">
                <x-icon :name="$icon" class="size-6"/>
                @if ($route === 'portal.support.index' && $replies)
                    <span class="nav-badge">{{ $replies }}</span>
                @endif
            </span>
            {{ $label }}
        </a>
    @endforeach
    <button type="button" class="bnav-item" data-drawer-toggle aria-controls="main-nav" aria-expanded="false">
        <span class="bnav-pill">
            <x-icon name="menu" class="size-6"/>
            @if ($toProcess)
                <span class="nav-badge">{{ $toProcess > 99 ? '99+' : $toProcess }}</span>
            @endif
        </span>
        المزيد
    </button>
</nav>

</body>
</html>
