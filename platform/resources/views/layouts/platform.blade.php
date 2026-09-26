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
<body class="min-h-screen antialiased">

@auth
<header class="ds-header">
    <div class="shell flex items-center gap-3 py-3">
        <a href="{{ route('admin.dashboard') }}" class="flex min-w-0 items-center gap-3">
            <span class="brand-tile">و</span>
            <span class="min-w-0">
                <span class="block font-heading text-lg leading-tight font-bold text-aeblack-900">وهج العراق</span>
                <span class="block text-xs text-ink-500">إدارة المنصّة</span>
            </span>
        </a>

        <div class="ms-auto flex items-center gap-2.5">
            <span class="grid size-10 shrink-0 place-items-center rounded-full bg-white font-heading text-sm font-bold text-primary-700"
                  aria-hidden="true">{{ mb_substr(auth()->user()->name, 0, 1) }}</span>
            <span class="hidden min-w-0 sm:block">
                <span class="block max-w-40 truncate text-sm font-semibold text-aeblack-900">{{ auth()->user()->name }}</span>
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

    <nav class="nav-strip" aria-label="أقسام المنصّة">
        <div class="shell tab-nav">
            @foreach ([
                ['admin.dashboard', 'نظرة عامة', 'admin.dashboard', 'grid'],
                ['admin.companies.index', 'الشركات', 'admin.companies.*', 'building'],
                ['admin.invoices.index', 'الفواتير', 'admin.invoices.*', 'invoice'],
                ['admin.plans.index', 'الباقات', 'admin.plans.*', 'tag'],
            ] as [$route, $label, $pattern, $icon])
                @php $active = request()->routeIs($pattern); @endphp
                <a href="{{ route($route) }}" class="tab-link {{ $active ? 'tab-link-active' : '' }}"
                   @if ($active) aria-current="page" @endif>
                    <x-icon :name="$icon" class="size-5"/>
                    {{ $label }}
                </a>
            @endforeach
        </div>
    </nav>
</header>

<main class="shell pt-6 pb-12">
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
@endauth

@guest
<div class="ds-header" aria-hidden="true"></div>
<main class="mx-auto max-w-screen-sm px-4 py-10">@yield('content')</main>
@endguest

</body>
</html>
