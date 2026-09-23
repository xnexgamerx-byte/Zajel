<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'لوحة زاجل') — إدارة المنصّة</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Readex+Pro:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- هوية المنصّة بنفسجية، ولوحات الشركات بألوانها — لا لبس بينهما --}}
    <style>:root { --brand: #7c3aed; }</style>
</head>
<body class="min-h-screen antialiased">

@auth
<div class="flex min-h-screen">
    <aside class="peer fixed inset-y-0 start-0 z-40 w-[17.5rem] shrink-0 p-3 max-lg:hidden max-lg:data-open:block
                  lg:sticky lg:top-0 lg:h-screen"
           data-sidebar>
        <div class="flex h-full flex-col rounded-[28px] border border-ink-900 bg-white max-lg:shadow-2xl">
            <div class="flex items-center gap-3 px-4 pb-2 pt-4">
                <span class="grid size-11 shrink-0 place-items-center rounded-2xl border border-ink-900 bg-plat-500 text-lg font-bold text-white">ز</span>
                <div class="min-w-0 flex-1">
                    <div class="text-[17px] font-semibold leading-tight">زاجل</div>
                    <div class="mt-0.5 text-xs text-ink-500">إدارة المنصّة</div>
                </div>
                <button type="button" data-sidebar-toggle class="icon-btn size-9 lg:hidden" aria-label="إغلاق القائمة">
                    <x-icon name="x" class="size-4"/>
                </button>
            </div>

            <nav class="flex-1 space-y-0.5 px-2.5 pb-4 pt-3">
                @foreach ([
                    ['admin.dashboard', 'نظرة عامة', 'admin.dashboard', 'grid'],
                    ['admin.companies.index', 'الشركات', 'admin.companies.*', 'building'],
                    ['admin.invoices.index', 'الفواتير', 'admin.invoices.*', 'invoice'],
                    ['admin.plans.index', 'الباقات', 'admin.plans.*', 'tag'],
                ] as [$route, $label, $pattern, $icon])
                    @php $active = request()->routeIs($pattern); @endphp
                    <a href="{{ route($route) }}" class="side-link {{ $active ? 'side-link-active' : '' }}"
                       @if ($active) aria-current="page" @endif>
                        <x-icon :name="$icon"/>
                        <span class="min-w-0 flex-1 truncate">{{ $label }}</span>
                    </a>
                @endforeach
            </nav>

            <div class="m-2.5 flex items-center gap-3 rounded-2xl border border-ink-900 p-2.5">
                <span class="grid size-10 shrink-0 place-items-center rounded-full bg-ink-900 text-white">
                    <x-icon name="user" class="size-5"/>
                </span>
                <div class="min-w-0 flex-1">
                    <div class="truncate text-sm font-medium">{{ auth()->user()->name }}</div>
                    <div class="truncate text-xs text-ink-500">{{ auth()->user()->role->label() }}</div>
                </div>
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button type="submit" class="icon-btn size-9" aria-label="تسجيل الخروج" title="تسجيل الخروج">
                        <x-icon name="logout" class="size-4 rtl:-scale-x-100"/>
                    </button>
                </form>
            </div>
        </div>
    </aside>

    <button type="button" data-sidebar-toggle aria-label="إغلاق القائمة" tabindex="-1"
            class="fixed inset-0 z-30 hidden bg-ink-900/40 backdrop-blur-[2px] peer-data-open:block lg:peer-data-open:hidden"></button>

    <div class="min-w-0 flex-1">
        <header class="sticky top-0 z-20 bg-ink-50/85 backdrop-blur lg:hidden">
            <div class="flex h-[4.5rem] items-center gap-3 px-4">
                <button type="button" data-sidebar-toggle class="icon-btn" aria-label="القائمة">
                    <x-icon name="menu" class="size-5"/>
                </button>
                <span class="font-semibold">زاجل — إدارة المنصّة</span>
            </div>
        </header>

        <main class="mx-auto max-w-screen-2xl px-4 pb-12 pt-2 lg:px-6 lg:pt-6">
            @if (session('success'))
                <div class="alert alert-ok mb-5" role="status">
                    <x-icon name="check" class="size-5 shrink-0"/>
                    <span class="font-medium">{{ session('success') }}</span>
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
</div>
@endauth

@guest
<main class="mx-auto max-w-screen-sm px-4 py-10">@yield('content')</main>
@endguest

</body>
</html>
