@extends('layouts.app')
@section('title', 'المراتب')

@section('content')
<div class="mb-4 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="page-title">المراتب</h1>
        <p class="mt-1 text-sm text-ink-500">كل مرتبة اسمٌ لمجموعة صلاحيات. تعديلها يسري فوراً على كل من يحملها.</p>
    </div>
    <a href="{{ route('permissions.ranks.create') }}" class="btn-primary">+ مرتبة جديدة</a>
</div>

@include('tenant.permissions._tabs')

@error('rank') <div class="card mb-4 border-bad-200 bg-bad-50 p-4 text-sm text-bad-700">{{ $message }}</div> @enderror

@if ($ranks->isEmpty())
    <section class="card mb-5 p-5">
        <h2 class="card-title">لا مراتب بعد</h2>
        <p class="card-hint mb-4">
            كل موظّف الآن يتبع دوره. أسرع بداية: أنشئ مرتبة من قالبٍ جاهز ثم عدّل ما شئت قبل الحفظ.
        </p>
        <a href="{{ route('permissions.ranks.create') }}" class="btn-primary">+ مرتبة جديدة</a>
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
                            {{ $rank->users_count ? 'يحملها '.\App\Support\Arabic::count($rank->users_count, ['موظّفٌ واحد', 'موظّفان', 'موظّفين', 'موظّفاً']) : 'لا يحملها أحد' }}
                        </p>
                    </div>
                    <a href="{{ route('permissions.ranks.edit', $rank) }}" class="btn-primary py-1">تعديل</a>
                </div>
                <p class="mt-3 text-xs text-ink-500">يرى في الشريط:</p>
                <div class="mt-1 flex flex-wrap gap-1.5">
                    @foreach ($groups as $group)
                        @php $count = count(array_intersect(array_keys($group['abilities']), $have)); @endphp
                        @continue($count === 0)
                        {{-- القائمة كلّها، أو بعضها: يُقال بالكلمة لا بكسر --}}
                        <span class="chip {{ $count === count($group['abilities']) ? 'chip-ok' : 'chip-mute' }}">
                            {{ $group['label'] }}{{ $count < count($group['abilities']) ? ' (بعضها)' : '' }}
                        </span>
                    @endforeach
                    @if ($have === [])
                        <span class="text-sm text-ink-400">لا تفتح شيئاً بعد.</span>
                    @endif
                </div>
                <div class="mt-auto flex flex-wrap items-center gap-3 pt-4 text-xs">
                    {{-- ما يراه أصحابها في «لوحة اليوم» ما لم يخصّصوا رئيسيّتهم (HomeLayout) --}}
                    <a href="{{ route('home.customize', ['rank' => $rank->id]) }}" class="font-semibold text-[var(--brand)] hover:underline">رئيسيّتها</a>
                    @unless ($rank->users_count)
                        <form method="POST" action="{{ route('permissions.ranks.destroy', $rank) }}" class="ms-auto">
                            @csrf @method('DELETE')
                            <button class="font-semibold text-bad-700 hover:underline">احذف المرتبة</button>
                        </form>
                    @endunless
                </div>
            </section>
        @endforeach
    </div>
@endif

{{-- للمتقدّم: ما يناله الموظّف بلا مرتبة. مطويٌّ فلا يزحم من يريد المراتب وحدها --}}
<details class="card p-5">
    <summary class="cursor-pointer font-medium">ماذا يرى الموظّف الذي ليست له مرتبة؟</summary>
    <p class="card-hint mt-2 mb-4">يتبع دوره. ✓ القائمة كلّها، و«بعضها» جزءٌ منها، و— لا شيء. وصاحب الشركة يملك كل شيء دائماً.</p>
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th>الدور</th>
                    <th>عدد من يتبعه</th>
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
                            <td class="{{ $have === 0 ? 'text-ink-400' : ($have === $all ? 'font-semibold text-ok-700' : 'text-ink-700') }}">
                                {{ $have === 0 ? '—' : ($have === $all ? '✓' : 'بعضها') }}
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</details>
@endsection
