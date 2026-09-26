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
        <style>:root { --brand: {{ $company->primary_color }}; }</style>
    @endisset
</head>
<body class="min-h-screen antialiased">

@auth
@php
    $user = auth()->user();
    $staff = $user->isStaff();
    $impersonating = session()->has(\App\Actions\Platform\ImpersonateCompany::SESSION_KEY);
    $menus = $staff ? \App\Support\StaffNavigation::for($user, request()) : [];
@endphp

@if ($impersonating)
    {{-- شريط لا يُخطأ: من يعمل داخل نظام شركة يجب أن يعرف أنه ليس نفسه --}}
    <div class="border-b border-primary-300 bg-primary-100 text-primary-900">
        <div class="shell flex flex-wrap items-center gap-3 py-2 text-sm">
            <x-icon name="eye" class="size-5 shrink-0"/>
            <span class="font-medium">
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
  رأس نظام تصميم الإمارات: الشريط الذهبيّ، ثم الشعار والبحث والحساب، ثم
  شريط القوائم. القوائم بترتيب النظام الذي اعتاده الموظّفون (StaffNavigation):
  البحث أوّلاً، ثم الصفحة الرئيسية … حتى الدفعات. على الشاشة الواسعة صفٌّ
  تنسدل منه القوائم، وعلى الهاتف قائمةٌ عمودية يفتحها زرّ القائمة.
--}}
<header class="ds-header">
    <div class="shell flex flex-wrap items-center gap-x-3 gap-y-2.5 py-3">
        @if ($staff)
            <button type="button" class="icon-btn xl:hidden" data-drawer-toggle aria-controls="main-nav" aria-expanded="false"
                    aria-label="القائمة">
                <x-icon name="menu" class="size-6"/>
            </button>
        @endif

        <a href="{{ route($staff ? 'dashboard' : 'shipments.index') }}" class="flex min-w-0 items-center gap-3">
            <span class="brand-tile">{{ $company->initial() }}</span>
            <span class="min-w-0">
                <span class="block truncate font-heading text-lg leading-tight font-bold text-aeblack-900">{{ $company->name }}</span>
                <span class="block text-xs text-ink-500">نظام إدارة الشحنات</span>
            </span>
        </a>

        @if ($staff)
            <form method="GET" action="{{ route('shipments.index') }}" role="search"
                  class="order-last basis-full md:order-none md:ms-auto md:max-w-md md:flex-1 md:basis-auto">
                <label for="global-search" class="sr-only">ابحث عن شحنة</label>
                <div class="relative">
                    <input id="global-search" name="q" value="{{ request('q') }}" class="field-input pe-11"
                           placeholder="ابحث برقم الوصل أو هاتف الزبون…">
                    <x-icon name="search" class="pointer-events-none absolute end-3.5 top-1/2 size-5 -translate-y-1/2 text-primary-700"/>
                </div>
            </form>
        @endif

        <div class="ms-auto flex items-center gap-2 md:ms-0">
            @if ($staff)
                <a href="{{ route('shipments.create') }}" class="btn-primary max-sm:hidden">
                    <x-icon name="plus" class="size-5"/>
                    شحنة جديدة
                </a>
            @endif

            <div class="flex items-center gap-2.5 border-s border-ink-200 ps-3">
                <span class="grid size-10 shrink-0 place-items-center rounded-full bg-primary-50 font-heading text-sm font-bold text-primary-800"
                      aria-hidden="true">{{ mb_substr($user->name, 0, 1) }}</span>
                <span class="hidden min-w-0 lg:block">
                    <span class="block max-w-40 truncate text-sm font-semibold text-aeblack-900">{{ $user->name }}</span>
                    <span class="block truncate text-xs text-ink-500">{{ $user->role->label() }}</span>
                </span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="icon-btn" aria-label="تسجيل الخروج" title="تسجيل الخروج">
                        <x-icon name="logout" class="size-5 rtl:-scale-x-100"/>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <nav id="main-nav" aria-label="القائمة الرئيسية"
         class="nav-strip {{ $staff ? 'max-xl:hidden max-xl:data-open:block' : '' }}" data-drawer>
        <div class="shell">
            <ul class="nav-menu">
                @if ($staff)
                    <li class="max-xl:hidden">
                        <button type="button" class="nav-item" data-focus="global-search"
                                aria-label="البحث عن شحنة" title="البحث عن شحنة">
                            <x-icon name="search" class="size-5 text-primary-600"/>
                        </button>
                    </li>

                    @foreach ($menus as $i => $menu)
                        <li class="relative" data-menu>
                            <button type="button" class="nav-item {{ $menu['active'] ? 'nav-item-active' : '' }}"
                                    data-menu-toggle aria-expanded="false" aria-controls="menu-{{ $i }}">
                                <x-icon :name="$menu['icon']" class="nav-item-icon"/>
                                <span>{{ $menu['label'] }}</span>
                                @if ($menu['badge'])
                                    <span class="nav-badge">{{ $menu['badge'] }}</span>
                                @endif
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
                @else
                    @php $here = request()->routeIs('shipments.*'); @endphp
                    <li>
                        <a href="{{ route('shipments.index') }}" class="nav-item {{ $here ? 'nav-item-active' : '' }}"
                           @if ($here) aria-current="page" @endif>
                            <x-icon name="box" class="nav-item-icon"/>
                            الشحنات
                        </a>
                    </li>
                @endif
            </ul>
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
