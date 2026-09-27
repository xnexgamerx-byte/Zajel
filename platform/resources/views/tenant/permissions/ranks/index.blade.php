@extends('layouts.app')
@section('title', 'المراتب')

@section('content')
<div class="mb-4 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="page-title">المراتب</h1>
        <p class="mt-1 text-sm text-ink-500">
            اسمٌ تختاره الشركة وما يفتحه من كل قائمة. تعديلها يسري فوراً على كل من يحملها.
        </p>
    </div>
    <a href="{{ route('permissions.ranks.create') }}" class="btn-primary">+ مرتبة</a>
</div>

@include('tenant.permissions._tabs')

@error('rank') <div class="card mb-4 border-bad-200 bg-bad-50 p-4 text-sm text-bad-700">{{ $message }}</div> @enderror

@if ($ranks->isEmpty())
    <section class="card mb-5 p-5">
        <h2 class="card-title">ابدأ من مراتب النظام الذي تعرفه</h2>
        <p class="card-hint mb-4">
            لا مراتب بعد: كل موظّف يتبع افتراضي دوره (الجدول أدناه). اختر قالباً فيُملأ باسمه وصلاحياته، ثم عدّل ما شئت.
        </p>
        <div class="flex flex-wrap gap-2">
            @foreach ($templates as $key => $template)
                <a href="{{ route('permissions.ranks.create', ['template' => $key]) }}" class="chip chip-mute hover:bg-primary-50 hover:text-primary-700"
                   title="{{ $template['hint'] }}">{{ $template['name'] }}</a>
            @endforeach
        </div>
    </section>
@else
    <div class="mb-5 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($ranks as $rank)
            @php $have = $rank->abilityList(); @endphp
            <section class="card flex flex-col p-5">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="card-title">{{ $rank->name }}</h2>
                        <p class="card-hint">
                            {{ $rank->users_count ? \App\Support\Arabic::count($rank->users_count, ['موظّفٌ واحد', 'موظّفان', 'موظّفين', 'موظّفاً']) : 'لا يحملها أحد' }}
                            · <span class="num">{{ count($have) }}</span> صلاحية
                        </p>
                    </div>
                    <a href="{{ route('permissions.ranks.edit', $rank) }}" class="btn-ghost py-1">تعديل</a>
                </div>
                <div class="mt-3 flex flex-wrap gap-1.5">
                    @foreach ($groups as $group)
                        @php $count = count(array_intersect(array_keys($group['abilities']), $have)); @endphp
                        @continue($count === 0)
                        <span class="chip {{ $count === count($group['abilities']) ? 'chip-ok' : 'chip-mute' }}">
                            {{ $group['label'] }}
                            @if ($count < count($group['abilities']))<span class="num">{{ $count }}/{{ count($group['abilities']) }}</span>@endif
                        </span>
                    @endforeach
                    @if ($have === [])
                        <span class="text-sm text-ink-400">لا تفتح شيئاً بعد.</span>
                    @endif
                </div>
                @unless ($rank->users_count)
                    <form method="POST" action="{{ route('permissions.ranks.destroy', $rank) }}" class="mt-auto pt-3">
                        @csrf @method('DELETE')
                        <button class="text-xs font-semibold text-bad-700 hover:underline">احذف المرتبة</button>
                    </form>
                @endunless
            </section>
        @endforeach
    </div>

    <details class="card mb-5 p-5">
        <summary class="cursor-pointer font-medium">مرتبة من قالب</summary>
        <p class="card-hint mb-3">أسماء المراتب كما في النظام الذي تعمل عليه الشركات، بصلاحياتٍ مقترحة تُعدَّل قبل الحفظ.</p>
        <div class="flex flex-wrap gap-2">
            @foreach ($templates as $key => $template)
                <a href="{{ route('permissions.ranks.create', ['template' => $key]) }}" class="chip chip-mute hover:bg-primary-50 hover:text-primary-700"
                   title="{{ $template['hint'] }}">{{ $template['name'] }}</a>
            @endforeach
        </div>
    </details>
@endif

<section class="card p-5">
    <h2 class="card-title">افتراضيّات الأدوار</h2>
    <p class="card-hint mb-4">ما يناله من لا مرتبة له. وصاحب الشركة يملك كل شيء دائماً.</p>
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th>الدور</th>
                    <th>يتبعه</th>
                    @foreach ($groups as $group)<th class="whitespace-nowrap">{{ $group['label'] }}</th>@endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($roles as $value => $label)
                    @php $defaults = \App\Support\Permissions\Ability::defaultsFor(\App\Enums\UserRole::from($value)); @endphp
                    <tr>
                        <td class="font-medium whitespace-nowrap">{{ $label }}</td>
                        <td class="num">{{ $byRole[$value] ?? 0 }}</td>
                        @foreach ($groups as $group)
                            @php
                                $have = count(array_intersect(array_keys($group['abilities']), $defaults));
                                $all = count($group['abilities']);
                            @endphp
                            <td class="num {{ $have === 0 ? 'text-ink-400' : ($have === $all ? 'font-semibold text-ok-700' : 'text-ink-700') }}">
                                {{ $have }}/{{ $all }}
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>
@endsection
