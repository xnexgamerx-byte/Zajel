<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="{{ $company->primary_color }}">
    <title>@yield('title', 'مهامي') — {{ $company->name }}</title>

    @include('partials.fonts')

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.company-style')
</head>
<body class="min-h-screen pb-24 antialiased">

<header class="ds-header sticky top-0 z-30 bg-canvas/90 backdrop-blur-md">
    <div class="flex h-16 items-center gap-3 px-4">
        <x-brand-tile :company="$company" size="size-10 text-base" />
        <div class="min-w-0 flex-1">
            <div class="truncate font-heading text-base leading-tight font-extrabold text-aeblack-950">{{ $courier->name }}</div>
            <div class="truncate text-xs text-ink-500">{{ $company->name }}</div>
        </div>
        {{-- المحادثة مع المكتب: نقطةٌ إن كتب المكتب ولم يُقرأ (docs/plan/38) --}}
        @if (\App\Support\FeatureGate::enabled(\App\Enums\Feature::Conversations))
            @php $chatUnread = \App\Models\CourierThread::where('courier_id', $courier->id)->where('courier_unread', true)->exists(); @endphp
            <a href="{{ route('courier.chat') }}" class="icon-btn relative"
               aria-label="المحادثة مع المكتب{{ $chatUnread ? '، رسالة جديدة' : '' }}">
                <x-icon name="chat" class="size-5"/>
                @if ($chatUnread)
                    <span class="nav-badge absolute -end-1 -top-1">١</span>
                @endif
            </a>
        @endif
        @php $unread = \App\Models\Announcement::for(auth()->user())->unreadBy(auth()->user())->count(); @endphp
        <a href="{{ route('courier.inbox') }}" class="icon-btn"
           aria-label="الإشعارات{{ $unread ? '، غير المقروء '.$unread : '' }}">
            <x-icon name="bell" class="size-5"/>
            @if ($unread)
                <span class="nav-badge absolute -end-1 -top-1">{{ $unread > 9 ? '9+' : $unread }}</span>
            @endif
        </a>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="icon-btn" aria-label="خروج" title="خروج">
                <x-icon name="logout" class="size-5 rtl:-scale-x-100"/>
            </button>
        </form>
    </div>
</header>

<main class="px-3 pt-3 pb-4">
    {{-- التنبيه ممتلئٌ هنا: المندوب يقرؤه في الشارع وتحت الشمس --}}
    @if (session('success'))
        <div class="alert mb-3 bg-aegreen-700 font-medium text-white" role="status">
            <x-icon name="check" class="size-5 shrink-0"/>
            <span>{{ session('success') }}</span>
        </div>
    @endif

    @if ($errors->any())
        <div class="alert mb-3 bg-aered-700 font-medium text-white" role="alert">
            <x-icon name="alert" class="size-5 shrink-0"/>
            <ul class="space-y-0.5">
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    @yield('content')
</main>

{{-- شريط سفلي (navigation bar): الإبهام يصله بلا مدّ اليد. والتبويب الحاليّ أيقونته في حبّةٍ ممتلئة بدرجةٍ هادئة --}}
<nav class="fixed inset-x-0 bottom-0 z-30 bg-white shadow-[0_-1px_12px_rgb(0_0_0/0.06)]"
     style="padding-bottom: env(safe-area-inset-bottom, 0px)">
    @php
        /*
        | الشريط يتبع دور المندوب: مندوب الاستلام لا نقد بيده فـ«حسابي»
        | عنده شاشة فارغة، وحسابه الحقيقي حصصه. ومندوب التوصيل لا حصص له.
        */
        $me = auth()->user()->courier;

        $tabs = collect([
            ['courier.tasks', 'مهامي', 'courier.tasks', $me?->delivers(), 'box'],
            ['courier.pickups', 'استلام', 'courier.pickups', $me?->picks(), 'clipboard'],
            ['courier.today', 'اليوم', 'courier.today', $me?->delivers(), 'calendar'],
            ['courier.shares', 'حصصي', 'courier.shares', $me?->picks(), 'wallet'],
            ['courier.cash', 'حسابي', 'courier.cash', $me?->delivers(), 'cash'],
        ])->filter(fn ($tab) => $tab[3])->values();
    @endphp

    <div class="grid" style="grid-template-columns: repeat({{ max(1, $tabs->count()) }}, minmax(0, 1fr))">
        @foreach ($tabs as [$route, $label, $pattern, $shown, $icon])
            @php $active = request()->routeIs($pattern); @endphp
            <a href="{{ route($route) }}"
               class="flex flex-col items-center gap-1 pt-3 pb-3 text-xs transition-colors
                      {{ $active ? 'font-extrabold text-aeblack-950' : 'font-semibold text-aeblack-700 hover:text-aeblack-950' }}"
               @if ($active) aria-current="page" @endif>
                <span class="grid h-8 w-14 place-items-center rounded-full transition-colors {{ $active ? 'bg-primary-200 text-primary-900' : '' }}">
                    <x-icon :name="$icon" class="size-5"/>
                </span>
                {{ $label }}
            </a>
        @endforeach
    </div>
</nav>

{{--
  موقع الهاتف (docs/plan/62): يُرسل ما دامت الشاشة مفتوحة، حدّث المندوب شيئاً أو لم يحدّث. والشركة
  تُلزم به افتراضاً: الموقع مقفولٌ فالشاشة مغطّاة بما يقول كيف يُفتح.
--}}
<div data-courier-location data-url="{{ route('courier.location') }}"
     data-required="{{ \App\Support\CourierLocation::required($company) ? '1' : '0' }}" hidden></div>
<div id="location-gate" data-location-gate hidden role="alertdialog" aria-modal="true" aria-labelledby="location-gate-title"
     class="fixed inset-0 z-50 grid place-items-center bg-aeblack-950/75 p-5">
    <div class="card w-full max-w-sm p-6 text-center">
        <span class="mx-auto mb-3 grid size-14 place-items-center rounded-full bg-brand-soft text-[var(--brand)]">
            <x-icon name="pin" class="size-7"/>
        </span>
        <h2 id="location-gate-title" class="text-lg font-extrabold">فعّل الموقع لتكمل</h2>
        <p data-location-reason class="mt-2 text-sm text-ink-600">
            الشركة تتابع موقعك أثناء العمل، والشاشة لا تعمل والموقع مقفول.
        </p>
        <ol class="mt-4 list-decimal space-y-1.5 ps-5 text-start text-sm text-ink-700">
            <li>شغّل «الموقع» (GPS) من أعلى شاشة الهاتف.</li>
            <li>اسمح لهذا الموقع بمعرفة مكانك: القفل بجانب العنوان ← الأذونات ← الموقع ← سماح.</li>
        </ol>
        <button type="button" data-location-retry class="btn-primary mt-5 w-full">فعّلته — أعد المحاولة</button>
    </div>
</div>

</body>
</html>
