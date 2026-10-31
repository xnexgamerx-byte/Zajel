{{--
  صورةٌ مصغّرة للتصميم (docs/plan/100) بلون مظهر الشركة: الشريط والقوائم والبطاقات كما تظهر لموظّفيها.
--}}
@php
    $accent = $theme->shades[500];
    $soft = $theme->shades[50];
@endphp
@switch($key)
    @case('soft')
        <span class="block h-32 overflow-hidden rounded-xl p-2" style="background:#e9e9eb" aria-hidden="true">
            <span class="flex items-center justify-between gap-1">
                <span class="h-3 w-8 rounded-full bg-white"></span>
                <span class="flex gap-0.5 rounded-full bg-white p-0.5">
                    <span class="h-2 w-5 rounded-full" style="background:#2e2b2b"></span>
                    <span class="h-2 w-4 rounded-full bg-ink-200"></span>
                    <span class="h-2 w-4 rounded-full bg-ink-200"></span>
                </span>
                <span class="size-3 rounded-full bg-white"></span>
            </span>
            <span class="mt-2 grid grid-cols-4 gap-1">
                <span class="col-span-2 h-12 rounded-lg bg-white"></span>
                <span class="h-12 rounded-lg" style="background:{{ $accent }}"></span>
                <span class="h-12 rounded-lg bg-white"></span>
            </span>
            <span class="mt-1 grid grid-cols-2 gap-1">
                <span class="h-10 rounded-lg bg-white"></span>
                <span class="h-10 rounded-lg bg-white"></span>
            </span>
        </span>
        @break
    @case('glass')
        <span class="block h-32 overflow-hidden rounded-xl p-2" style="background:radial-gradient(120% 90% at 85% 0%,#e3e9e7,#c3cfcb 40%,#9fb0ab)" aria-hidden="true">
            <span class="flex items-center justify-between gap-1">
                <span class="size-3 rounded-full" style="background:#ecf26a"></span>
                <span class="flex gap-0.5 rounded-full p-0.5" style="background:rgb(255 255 255/.45)">
                    <span class="h-2 w-5 rounded-full" style="background:#ecf26a"></span>
                    <span class="h-2 w-4 rounded-full" style="background:rgb(255 255 255/.6)"></span>
                    <span class="h-2 w-4 rounded-full" style="background:rgb(255 255 255/.6)"></span>
                </span>
                <span class="h-3 w-6 rounded-full" style="background:rgb(255 255 255/.45)"></span>
            </span>
            <span class="mt-2 grid grid-cols-3 gap-1">
                <span class="row-span-2 h-[5.5rem] rounded-lg" style="background:rgb(255 255 255/.32);border:1px solid rgb(255 255 255/.6)"></span>
                <span class="h-10 rounded-lg" style="background:rgb(255 255 255/.32);border:1px solid rgb(255 255 255/.6)"></span>
                <span class="row-span-2 h-[5.5rem] rounded-lg" style="background:rgb(255 255 255/.32);border:1px solid rgb(255 255 255/.6)"></span>
                <span class="h-[2.75rem] rounded-lg" style="background:linear-gradient(180deg,rgb(255 255 255/.3),#ecf26a)"></span>
            </span>
        </span>
        @break
    @default
        <span class="flex h-32 gap-1.5 overflow-hidden rounded-xl p-2" style="background:{{ $soft }}" aria-hidden="true">
            <span class="flex w-6 flex-col items-center gap-1.5 pt-1">
                <span class="size-4 rounded-md" style="background:{{ $theme->shades[600] }}"></span>
                @foreach (range(1, 5) as $i)
                    <span class="h-2 w-4 rounded-full" style="background:{{ $i === 1 ? $theme->shades[200] : 'rgb(0 0 0/.08)' }}"></span>
                @endforeach
            </span>
            <span class="flex flex-1 flex-col gap-1">
                <span class="h-3 rounded-full bg-white"></span>
                <span class="grid grid-cols-3 gap-1">
                    <span class="h-10 rounded-lg" style="background:{{ $theme->shades[700] }}"></span>
                    <span class="h-10 rounded-lg bg-white"></span>
                    <span class="h-10 rounded-lg bg-white"></span>
                </span>
                <span class="h-12 rounded-lg bg-white"></span>
            </span>
        </span>
@endswitch
