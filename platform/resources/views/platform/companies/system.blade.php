@extends('layouts.platform')
@section('title', 'نظام '.$company->name)

@section('content')
@php
    $open = collect($features)->where('enabled', true);
    $monthly = $open->sum('price');
@endphp

<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <div class="flex items-center gap-3">
            <span class="h-5 w-5 rounded-lg" style="background: {{ $company->primary_color }}"></span>
            <h1 class="page-title">نظام {{ $company->name }}</h1>
        </div>
        <p class="mt-1 text-sm text-ink-500">
            ما يعمل في نظامها من ميزات وبكم، ومظهره، وترتيب قوائم موظّفيها — لهذه الشركة وحدها، لا يمسّ غيرها.
        </p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('admin.companies.show', $company) }}" class="btn-ghost">رجوع للشركة</a>
        <form method="POST" action="{{ route('admin.companies.impersonate', $company) }}">
            @csrf
            <button class="btn-ghost" @disabled(! $company->isOperational())>ادخل نظامها</button>
        </form>
    </div>
</div>

<div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
    <a href="#features" class="stat block">
        <div class="stat-label">ميزاتٌ مفتوحة</div>
        <div class="mt-1 text-2xl font-bold text-ink-900">
            <span class="num">{{ $open->count() }}</span>
            <span class="text-sm font-medium text-ink-500">من <span class="num">{{ count($features) }}</span></span>
        </div>
    </a>
    <a href="#features" class="stat block">
        <div class="stat-label">رسومها الشهرية على الشركة</div>
        <div class="mt-1 text-2xl font-bold text-[var(--brand)]">
            <span class="num">{{ number_format($monthly) }}</span>
            <span class="text-sm font-medium text-ink-500">د.ع</span>
        </div>
    </a>
    <a href="#theme" class="stat block">
        <div class="stat-label">المظهر</div>
        <div class="mt-2 flex items-center gap-2">
            <span class="flex">
                @foreach ([300, 500, 700] as $step)
                    <span class="-ms-1 size-5 rounded-full ring-2 ring-white first:ms-0" style="background: {{ $theme->shades[$step] }}"></span>
                @endforeach
            </span>
            <span class="font-semibold text-ink-900">{{ $theme->name() }}</span>
        </div>
    </a>
</div>

<section id="features" class="card mb-5 scroll-mt-6 p-5">
    <h2 class="card-title">الميزات</h2>
    <p class="card-hint">
        افتح الميزة لهذه الشركة أو أغلقها، واكتب رسمها الشهري — يُضاف إلى فاتورتها الشهرية بالأيام التي عملت فيها،
        و<span class="num">0</span> مجّاناً. والميزة الجديدة مطفأةٌ في كل الشركات حتى تفتحها لشركةٍ بعينها.
    </p>

    <div class="mt-2 divide-y divide-ink-100">
        @foreach ($features as $row)
            @php [$feature, $enabled] = [$row['feature'], $row['enabled']]; @endphp
            <form method="POST" action="{{ route('admin.companies.features.update', $company) }}"
                  class="grid grid-cols-1 items-center gap-3 py-4 lg:grid-cols-[1fr_auto]" id="feature-{{ $feature->value }}">
                @csrf
                <input type="hidden" name="feature" value="{{ $feature->value }}">

                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-semibold text-ink-900">{{ $feature->label() }}</span>
                        @if ($enabled)
                            <span class="chip chip-ok">
                                مفتوحة{{ $row['price'] ? ' — '.number_format($row['price']).' د.ع شهرياً' : ' مجّاناً' }}
                            </span>
                        @else
                            <span class="chip chip-mute">مغلقة</span>
                        @endif
                        @unless ($feature->included())
                            <span class="chip chip-info">إضافة</span>
                        @endunless
                    </div>
                    <p class="mt-1 text-sm text-ink-500">{{ $feature->description() }}</p>
                    <p class="mt-1 text-xs text-ink-400">
                        @if ($row['decided'])
                            منذ <span class="num">{{ $row['since']->format('Y-m-d') }}</span>
                        @elseif ($feature->included())
                            من أصل النظام: مفتوحةٌ لكل شركةٍ ما لم تُغلقها لها.
                        @else
                            مطفأةٌ حتى تفتحها لهذه الشركة.
                        @endif
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <input type="hidden" name="enabled" value="0">
                    <label class="inline-flex items-center gap-2 text-sm font-medium">
                        <input type="checkbox" name="enabled" value="1" @checked($enabled)>
                        مفتوحة
                    </label>
                    <label class="sr-only" for="price-{{ $feature->value }}">الرسم الشهري لـ{{ $feature->label() }}</label>
                    <div class="relative w-44">
                        <input id="price-{{ $feature->value }}" name="monthly_price" inputmode="numeric" class="field-input num ps-20 text-left" dir="ltr"
                               value="{{ $row['price'] ?: '' }}" placeholder="0" autocomplete="off" data-money>
                        <span class="pointer-events-none absolute inset-y-0 end-4 flex items-center text-xs text-ink-400">د.ع/شهر</span>
                    </div>
                    <button type="submit" class="btn-ghost">احفظ</button>
                </div>
            </form>
        @endforeach
    </div>
</section>

<section id="theme" class="card mb-5 scroll-mt-6 p-5">
    <h2 class="card-title">المظهر</h2>
    <p class="card-hint">
        لون الأزرار والروابط والقائمة الحالية في نظام الشركة كلّه: شاشات موظّفيها، وبوّابة التاجر، وتطبيق المندوب،
        وصفحة التتبّع. وكل لونٍ هنا بتباينٍ يُقرأ عليه النصّ؛ و«من لون الشعار» يُشتقّ من لونها
        <span class="num" dir="ltr">{{ $company->primary_color }}</span> الذي تغيّره من «بيانات الشركة».
    </p>

    <form method="POST" action="{{ route('admin.companies.theme', $company) }}">
        @csrf
        <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            @foreach ($themes as $option)
                <label class="cursor-pointer rounded-2xl border border-ink-200 bg-white p-3 transition hover:border-ink-300 has-checked:border-transparent has-checked:ring-2 has-checked:ring-[var(--brand)]">
                    <input type="radio" name="theme" value="{{ $option->key }}" class="sr-only" @checked($option->key === $theme->key)>
                    <span class="flex">
                        @foreach ([100, 300, 500, 600, 800] as $step)
                            <span class="h-6 flex-1 first:rounded-s-lg last:rounded-e-lg" style="background: {{ $option->shades[$step] }}"></span>
                        @endforeach
                    </span>
                    <span class="mt-2 block text-sm font-semibold text-ink-900">{{ $option->name() }}</span>
                    <span class="mt-2 flex items-center gap-2">
                        <span class="rounded-full px-3 py-1 text-xs font-semibold text-white" style="background: {{ $option->shades[600] }}">زرّ</span>
                        <span class="text-xs font-semibold underline underline-offset-4" style="color: {{ $option->shades[700] }}">رابط</span>
                    </span>
                </label>
            @endforeach
        </div>
        <button type="submit" class="btn-primary mt-4">احفظ المظهر</button>
    </form>
</section>

<section id="menus" class="card scroll-mt-6 p-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="card-title">ترتيب قوائم الموظّفين</h2>
            <p class="card-hint">
                بأيّ ترتيبٍ تظهر القوائم في شريط موظّفي الشركة، والروابط داخل كل قائمة. كل ضغطةٍ تُحفظ فوراً.
                وما لا يفتحه الموظّف — بصلاحيته، أو لأن ميزته مغلقة — لا يظهر له أصلاً.
            </p>
        </div>
        @if ($arranged)
            <form method="POST" action="{{ route('admin.companies.navigation', $company) }}">
                @csrf
                <button type="submit" name="reset" value="1" class="btn-ghost">أعد الترتيب الأصليّ</button>
            </form>
        @endif
    </div>

    <form method="POST" action="{{ route('admin.companies.navigation', $company) }}">
        @csrf
        <ol class="mt-3 space-y-2">
            @foreach ($menus as $key => [$label, $icon, $links])
                <li class="rounded-2xl border border-ink-200 bg-white">
                    <div class="flex items-center gap-3 px-3 py-2">
                        <span class="num w-5 text-center text-sm text-ink-400">{{ $loop->iteration }}</span>
                        <x-icon :name="$icon" class="size-5 text-ink-500"/>
                        <span class="font-semibold text-ink-900">{{ $label }}</span>
                        <span class="ms-auto flex gap-1">
                            <button type="submit" name="move" value="menu|{{ $key }}|up" class="icon-btn size-9" @disabled($loop->first)
                                    aria-label="ارفع «{{ $label }}»" title="ارفع">
                                <x-icon name="chevron-down" class="size-4 rotate-180"/>
                            </button>
                            <button type="submit" name="move" value="menu|{{ $key }}|down" class="icon-btn size-9" @disabled($loop->last)
                                    aria-label="أنزل «{{ $label }}»" title="أنزل">
                                <x-icon name="chevron-down" class="size-4"/>
                            </button>
                        </span>
                    </div>

                    @if (count($links) > 1)
                        <details id="menu-{{ $key }}" class="scroll-mt-6 border-t border-ink-100 px-3 py-2" @if (session('opened') === $key) open @endif>
                            <summary class="cursor-pointer text-sm text-ink-600">روابطها (<span class="num">{{ count($links) }}</span>)</summary>
                            <ol class="mt-2 space-y-1">
                                @foreach ($links as $link)
                                    @php
                                        $linkKey = \App\Support\StaffNavigation::linkKey($link);
                                        $gate = \App\Support\FeatureGate::guarding($link[0]);
                                    @endphp
                                    <li class="flex items-center gap-2 rounded-lg px-2 py-1 text-sm hover:bg-ink-50">
                                        <span class="min-w-0 truncate">{{ $link[1] }}</span>
                                        @if ($gate && ! $company->hasFeature($gate))
                                            <span class="chip chip-mute">ميزتها مغلقة</span>
                                        @endif
                                        <span class="ms-auto flex shrink-0 gap-1">
                                            <button type="submit" name="move" value="link|{{ $key }}|{{ $linkKey }}|up" class="icon-btn size-8" @disabled($loop->first)
                                                    aria-label="ارفع «{{ $link[1] }}»" title="ارفع">
                                                <x-icon name="chevron-down" class="size-4 rotate-180"/>
                                            </button>
                                            <button type="submit" name="move" value="link|{{ $key }}|{{ $linkKey }}|down" class="icon-btn size-8" @disabled($loop->last)
                                                    aria-label="أنزل «{{ $link[1] }}»" title="أنزل">
                                                <x-icon name="chevron-down" class="size-4"/>
                                            </button>
                                        </span>
                                    </li>
                                @endforeach
                            </ol>
                        </details>
                    @endif
                </li>
            @endforeach
        </ol>
    </form>
</section>
@endsection
