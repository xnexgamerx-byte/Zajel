<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'إدارة المنصّة') — وهج العراق</title>

    @include('partials.fonts')

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- شعار المنصّة بمرجانيّ «وهج»، وشعارات الشركات بألوانها --}}
    <style>:root { --company: var(--color-primary-600); }</style>
</head>
<body class="min-h-screen antialiased {{ auth()->check() ? 'has-rail' : '' }}">

@auth
@php
    $sections = [
        ['admin.dashboard', 'نظرة عامة', 'admin.dashboard', 'grid'],
        ['admin.companies.index', 'الشركات', 'admin.companies.*', 'building'],
        ['admin.features.index', 'الميزات', 'admin.features.*', 'bolt'],
        ['admin.subscriptions.index', 'الاشتراكات', 'admin.subscriptions.*', 'wallet'],
        ['admin.invoices.index', 'الفواتير', 'admin.invoices.*', 'invoice'],
        ['admin.plans.index', 'الباقات', 'admin.plans.*', 'tag'],
        ['admin.settings', 'الإعدادات', 'admin.settings*', 'sliders'],
    ];
    // دفعاتٌ أبلغت عنها الشركات تنتظر التأكيد (docs/plan/36)
    $pending = \App\Models\PaymentNotice::acrossCompanies()->where('status', 'pending')->count();
@endphp

{{-- «نبض» في لوحة المنصّة (docs/plan/40): سبعة أقسام في الشريط الجانبي، وزرّ «شركة جديدة» العائم --}}
<nav id="main-nav" aria-label="أقسام المنصّة" class="rail" data-drawer>
    <div class="rail-head">
        <a href="{{ route('admin.dashboard') }}" class="rail-brand" title="وهج العراق">
            <span class="brand-tile">و</span>
            <span class="min-w-0 lg:hidden">
                <span class="block truncate font-heading text-base leading-tight font-extrabold text-aeblack-950">وهج العراق</span>
                <span class="block text-xs text-ink-500">إدارة المنصّة</span>
            </span>
        </a>
        <button type="button" class="icon-btn lg:hidden" data-drawer-close aria-label="أغلق القائمة">
            <x-icon name="x" class="size-6"/>
        </button>
    </div>

    <a href="{{ route('admin.companies.create') }}" class="rail-fab" aria-label="شركة جديدة" title="شركة جديدة">
        <x-icon name="plus" class="size-7"/>
    </a>

    <ul class="rail-menu">
        @foreach ($sections as [$route, $label, $pattern, $icon])
            @php $active = request()->routeIs($pattern); @endphp
            <li>
                <a href="{{ route($route) }}" class="nav-item {{ $active ? 'nav-item-active' : '' }}"
                   @if ($active) aria-current="page" @endif>
                    <span class="nav-pill">
                        <x-icon :name="$icon" class="size-6"/>
                        @if ($route === 'admin.invoices.index' && $pending)
                            <span class="nav-badge">{{ $pending }}</span>
                        @endif
                    </span>
                    <span class="nav-label">{{ $label }}</span>
                </a>
            </li>
        @endforeach
    </ul>

    <div class="rail-foot">
        <span class="avatar" aria-hidden="true">{{ mb_substr(auth()->user()->name, 0, 1) }}</span>
        <span class="min-w-0 flex-1">
            <span class="block truncate text-sm font-bold text-aeblack-900">{{ auth()->user()->name }}</span>
            <span class="block truncate text-xs text-ink-500">{{ auth()->user()->role->label() }}</span>
        </span>
        <form method="POST" action="{{ route('admin.logout') }}">
            @csrf
            <button type="submit" class="icon-btn" aria-label="تسجيل الخروج" title="تسجيل الخروج">
                <x-icon name="logout" class="size-5 rtl:-scale-x-100"/>
            </button>
        </form>
    </div>
</nav>
<div class="drawer-scrim" data-drawer-close></div>

<div class="app-frame">
<header class="app-bar">
    <div class="shell flex items-center gap-3 py-3 lg:py-4">
        <button type="button" class="icon-btn lg:hidden" data-drawer-toggle aria-controls="main-nav" aria-expanded="false"
                aria-label="القائمة">
            <x-icon name="menu" class="size-6"/>
        </button>
        <a href="{{ route('admin.dashboard') }}" class="flex min-w-0 items-center gap-3">
            <span class="brand-tile size-10 text-base lg:hidden">و</span>
            <span class="min-w-0">
                <span class="block font-heading text-lg leading-tight font-extrabold text-aeblack-950">وهج العراق</span>
                <span class="block text-xs text-ink-500">إدارة المنصّة</span>
            </span>
        </a>

        <div class="ms-auto flex items-center gap-2.5">
            <span class="avatar" aria-hidden="true">{{ mb_substr(auth()->user()->name, 0, 1) }}</span>
            <span class="hidden min-w-0 sm:block">
                <span class="block max-w-40 truncate text-sm font-bold text-aeblack-900">{{ auth()->user()->name }}</span>
                <span class="block truncate text-xs text-ink-500">{{ auth()->user()->role->label() }}</span>
            </span>
            <form method="POST" action="{{ route('admin.logout') }}">
                @csrf
                <button type="submit" class="icon-btn bg-white" aria-label="تسجيل الخروج" title="تسجيل الخروج">
                    <x-icon name="logout" class="size-5 rtl:-scale-x-100"/>
                </button>
            </form>
        </div>
    </div>
</header>

<main class="shell pt-2 pb-12 lg:pt-3">
    @if (session('success'))
        <div class="alert alert-ok mb-5" role="status">
            <x-icon name="check" class="size-5 shrink-0"/>
            <span class="font-medium">{{ session('success') }}</span>
        </div>
    @endif

    {{-- بلا نطاق: هذا العنوان يفتح الشركة الافتراضية. وإن لم تُضبَط أو لم تُسجَّل
         يرى زوّاره «الصفحة غير موجودة» ولا يعرف أحدٌ لماذا — فالسبب هنا.
         (وفي التطوير تُختار الشركة بـ ?company= فلا معنى له) --}}
    @php
        $defaultSlug = \App\Support\Tenancy\DefaultCompany::slug();
        $freeAddressIdle = ! app()->environment('local', 'testing')
            && \App\Support\Tenancy\DefaultCompany::covers(request()->getHost())
            && ($defaultSlug === '' || ! \App\Models\Company::where('slug', $defaultSlug)->exists());
    @endphp
    @if ($freeAddressIdle)
        <div class="alert alert-bad mb-5" role="alert">
            <x-icon name="alert" class="size-5 shrink-0"/>
            <div>
                <div class="font-semibold">
                    العنوان <span class="num">{{ request()->getHost() }}</span> لا يفتح نظام أيّ شركة: يرى زوّاره «الصفحة غير موجودة».
                </div>
                <p class="mt-1">
                    @if ($defaultSlug === '')
                        المتغيّر <span class="num">ZAJEL_DEFAULT_COMPANY</span> لم يصل إلى الخدمة.
                        أضفه في متغيّراتها بالنطاق الفرعي للشركة، ثم Deploy.
                    @else
                        <span class="num">ZAJEL_DEFAULT_COMPANY={{ $defaultSlug }}</span> ولا شركة بهذا النطاق الفرعي.
                        سجّلها به، أو اجعل المتغيّر نطاق شركةٍ مسجّلة ثم Deploy.
                    @endif
                </p>
            </div>
        </div>
    @endif

    @if ($errors->any() && ! request()->routeIs('admin.login'))
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
@endauth

@guest
<div class="ds-header" aria-hidden="true"></div>
<main class="mx-auto max-w-screen-sm px-4 py-10">@yield('content')</main>
@endguest

</body>
</html>
