@extends('layouts.app')
@section('title', 'أرشيف الكشوف')

@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="text-xl font-bold">أرشيف الكشوف</h1>
        <p class="mt-1 text-sm text-ink-500">ما خرج من مركزٍ أو وصل إليه، ومتى، وهل وصل كاملاً.</p>
    </div>
    <a href="{{ route('manifests.index') }}" class="btn-ghost">الكشوف المتداولة</a>
</div>

<form method="GET" class="card mb-5 flex flex-wrap items-end gap-3 p-4">
    <div>
        <label class="field-label" for="hub_id">المركز</label>
        <select id="hub_id" name="hub_id" class="field-input">
            @foreach ($hubs as $option)
                <option value="{{ $option->id }}" @selected($hub?->id === $option->id)>
                    {{ $option->name }}{{ $option->is_active ? '' : ' (موقوف)' }}
                </option>
            @endforeach
        </select>
    </div>
    <div>
        <span class="field-label">الاتجاه</span>
        <div class="flex gap-1.5">
            @foreach (['out' => 'المُرسَل منه', 'in' => 'الوارد إليه'] as $value => $label)
                <label class="chip cursor-pointer {{ $direction === $value ? 'chip-info' : 'chip-mute' }}">
                    <input type="radio" name="direction" value="{{ $value }}" class="sr-only" @checked($direction === $value)
                           onchange="this.form.submit()">
                    {{ $label }}
                </label>
            @endforeach
        </div>
    </div>
    <div>
        <label class="field-label" for="from">من</label>
        <input id="from" name="from" type="date" class="field-input" value="{{ $period->from->toDateString() }}">
    </div>
    <div>
        <label class="field-label" for="to">إلى</label>
        <input id="to" name="to" type="date" class="field-input" value="{{ $period->to->toDateString() }}">
    </div>
    <label class="flex items-center gap-2 pb-2 text-sm">
        <input type="checkbox" name="missing" value="1" @checked(request()->boolean('missing'))>
        ما نقص منه كيس
    </label>
    <button type="submit" class="btn-primary">طبّق</button>
</form>

@if ($manifests->isEmpty())
    <section class="card p-10 text-center">
        <p class="text-ink-500">لا كشوف {{ $direction === 'out' ? 'خرجت من' : 'وصلت إلى' }} {{ $hub?->name }} في هذه المدّة.</p>
    </section>
@else
    <section class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>الكشف</th>
                        <th>{{ $direction === 'out' ? 'إلى' : 'من' }}</th>
                        <th>السائق</th>
                        <th>خرج</th>
                        <th>وصل</th>
                        <th>أكياس</th>
                        <th>شحنات</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($manifests as $manifest)
                        <tr>
                            <td>
                                <a href="{{ route('manifests.show', $manifest) }}" class="num font-semibold hover:underline">{{ $manifest->code }}</a>
                            </td>
                            <td class="text-ink-600">{{ $direction === 'out' ? $manifest->toHub?->name : $manifest->fromHub?->name }}</td>
                            <td class="text-sm text-ink-600">
                                {{ $manifest->driver_name ?? '—' }}
                                @if ($manifest->vehicle_number)
                                    <span class="block text-xs text-ink-400">{{ $manifest->vehicle_number }}</span>
                                @endif
                            </td>
                            <td class="num whitespace-nowrap text-xs text-ink-600">{{ $manifest->departed_at?->format('Y-m-d H:i') }}</td>
                            <td class="num whitespace-nowrap text-xs text-ink-600">{{ $manifest->arrived_at?->format('Y-m-d H:i') ?? '—' }}</td>
                            <td>
                                <span class="num">{{ number_format($manifest->bags_count) }}</span>
                                @if ($manifest->missing_count > 0)
                                    {{-- الكشف المُقفَل بنقصٍ لا يُنسى في الأرشيف: هنا يُبحث عن الضائع --}}
                                    <span class="chip chip-bad ms-1">نقص {{ number_format($manifest->missing_count) }}</span>
                                @endif
                            </td>
                            <td class="num">{{ number_format($manifest->shipments_count) }}</td>
                            <td class="text-end">
                                <a href="{{ route('manifests.print', $manifest) }}" class="btn-ghost">اطبع</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <div class="mt-4">{{ $manifests->links() }}</div>
@endif
@endsection
