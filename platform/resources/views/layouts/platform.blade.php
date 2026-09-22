<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'لوحة زاجل') — إدارة المنصّة</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- هوية المنصّة بنفسجية، ولوحات الشركات بألوانها — لا لبس بينهما --}}
    <style>:root { --brand: #7c3aed; }</style>
</head>
<body class="min-h-screen antialiased">

@auth
<div class="flex min-h-screen">
    <aside class="fixed inset-y-0 z-40 hidden w-60 shrink-0 bg-ink-950 text-ink-300 lg:static lg:block"
           data-sidebar hidden>
        <div class="flex h-full flex-col">
            <div class="flex h-16 items-center gap-2.5 px-4">
                <span class="grid h-9 w-9 place-items-center rounded-xl bg-plat-600 text-sm font-black text-white">ز</span>
                <div>
                    <div class="text-sm font-bold text-white">زاجل</div>
                    <div class="text-[11px] text-ink-500">إدارة المنصّة</div>
                </div>
            </div>

            <nav class="flex-1 space-y-0.5 px-2 pb-4">
                @foreach ([
                    ['admin.dashboard', 'نظرة عامة', 'admin.dashboard'],
                    ['admin.companies.index', 'الشركات', 'admin.companies.*'],
                    ['admin.invoices.index', 'الفواتير', 'admin.invoices.*'],
                    ['admin.plans.index', 'الباقات', 'admin.plans.*'],
                ] as [$route, $label, $pattern])
                    @php $active = request()->routeIs($pattern); @endphp
                    <a href="{{ route($route) }}"
                       class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium transition
                              {{ $active ? 'bg-plat-600 text-white' : 'text-ink-400 hover:bg-white/5 hover:text-white' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </nav>

            <div class="border-t border-white/10 p-3">
                <div class="mb-2 px-1">
                    <div class="truncate text-sm font-semibold text-white">{{ auth()->user()->name }}</div>
                    <div class="text-xs text-ink-500">{{ auth()->user()->role->label() }}</div>
                </div>
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button type="submit"
                            class="flex w-full items-center rounded-lg px-3 py-2 text-sm font-medium text-ink-400 hover:bg-white/5 hover:text-white">
                        تسجيل الخروج
                    </button>
                </form>
            </div>
        </div>
    </aside>

    <div class="min-w-0 flex-1">
        <header class="sticky top-0 z-30 border-b border-ink-200 bg-white/85 backdrop-blur lg:hidden">
            <div class="flex h-16 items-center gap-3 px-4">
                <button type="button" data-sidebar-toggle class="btn-ghost px-3 py-2" aria-label="القائمة">☰</button>
                <span class="font-bold">زاجل — إدارة المنصّة</span>
            </div>
        </header>

        <main class="mx-auto max-w-screen-2xl px-4 py-6">
            @if (session('success'))
                <div class="mb-4 rounded-xl border border-ok-200 bg-ok-50 px-4 py-3 text-sm font-medium text-ok-700">
                    {{ session('success') }}
                </div>
            @endif

            @if ($errors->any() && ! request()->routeIs('admin.login'))
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
<main class="mx-auto max-w-screen-sm px-4 py-10">@yield('content')</main>
@endguest

</body>
</html>
