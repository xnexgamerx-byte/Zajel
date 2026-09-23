@extends('layouts.app')
@section('title', 'المناطق والمندوبون')

@section('content')
<div class="mb-5">
    <h1 class="page-title">توزيع المندوبين على المناطق</h1>
    <p class="mt-1 text-sm text-ink-500">
        المحافظة بلا مندوب هي ما يجعل شحنةً تنام في المخزن بلا أن يسأل عنها أحد.
    </p>
</div>

@if ($uncovered->isNotEmpty())
    <div class="card mb-5 border-warn-200 bg-warn-50 p-4 text-sm text-warn-700">
        <span class="font-semibold">محافظات فيها شحنات تنتظر ولا مندوب مُسنَد إليها:</span>
        {{ $uncovered->pluck('name_ar')->implode(' · ') }}
    </div>
@endif

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <div class="lg:col-span-2">
        <section class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th>المحافظة</th>
                            <th>تنتظر الآن</th>
                            <th>المندوبون المُسنَدون</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($governorates as $governorate)
                            @php $assigned = $zones[$governorate->id] ?? collect(); @endphp
                            <tr class="{{ $assigned->isEmpty() && ($waiting[$governorate->id] ?? 0) > 0 ? 'bg-warn-50' : '' }}">
                                <td class="font-medium">{{ $governorate->name_ar }}</td>
                                <td class="num {{ ($waiting[$governorate->id] ?? 0) > 0 ? 'font-semibold' : 'text-ink-400' }}">
                                    {{ number_format($waiting[$governorate->id] ?? 0) }}
                                </td>
                                <td>
                                    @forelse ($assigned as $zone)
                                        <span class="chip {{ $zone->courier?->status === 'active' ? 'chip-ok' : 'chip-mute' }} me-1 mb-1">
                                            {{ $zone->courier?->name }}
                                            <form method="POST" action="{{ route('zones.destroy', $zone) }}" class="inline">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="ms-1 text-bad-700" aria-label="ألغِ الإسناد">×</button>
                                            </form>
                                        </span>
                                    @empty
                                        <span class="text-sm text-ink-400">لا أحد</span>
                                    @endforelse
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <section class="card h-fit p-5">
        <h2 class="card-title">إسناد جديد</h2>
        <form method="POST" action="{{ route('zones.store') }}" class="mt-3 space-y-3">
            @csrf
            <div>
                <label class="field-label" for="courier_id">المندوب</label>
                <select id="courier_id" name="courier_id" class="field-input" required>
                    @foreach ($couriers as $courier)
                        <option value="{{ $courier->id }}">{{ $courier->name }}</option>
                    @endforeach
                </select>
                @error('courier_id') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="field-label" for="governorate_id">المحافظة</label>
                <select id="governorate_id" name="governorate_id" class="field-input" required>
                    @foreach ($governorates as $governorate)
                        <option value="{{ $governorate->id }}">{{ $governorate->name_ar }}</option>
                    @endforeach
                </select>
                @error('governorate_id') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn-primary w-full" @disabled($couriers->isEmpty())>أسنِد</button>
            <p class="field-hint">الإسناد على مستوى المحافظة. ولمندوبَين المحافظة نفسها إن لزم.</p>
        </form>
    </section>
</div>
@endsection
