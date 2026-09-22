@extends('layouts.app')
@section('title', 'كشوف النقل')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="text-xl font-bold">كشوف النقل</h1>
        <p class="mt-1 text-sm text-ink-500">سيارة واحدة، سائق واحد، عدّة أكياس — وورقة تُثبت مَن سلّم ومَن استلم.</p>
    </div>
    @if ($inbound)
        <a href="{{ route('manifests.inbound') }}" class="card px-5 py-3 text-center transition hover:border-brand">
            <div class="text-xs text-ink-500">في الطريق إليك</div>
            <div class="num text-2xl font-bold text-info-700">{{ number_format($inbound) }}</div>
        </a>
    @endif
</div>

<div class="mb-5 flex flex-wrap gap-2">
    @foreach (['' => 'المتداولة', 'draft' => 'قيد التحميل', 'dispatched' => 'في الطريق', 'arrived' => 'وصل'] as $value => $label)
        <a href="{{ route('manifests.index', array_filter(['status' => $value])) }}"
           class="chip {{ request('status', '') === $value ? 'chip-info' : 'chip-mute' }}">{{ $label }}</a>
    @endforeach
</div>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <div class="lg:col-span-2">
        <section class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th>الكشف</th>
                            <th>المسار</th>
                            <th>السائق</th>
                            <th>أكياس</th>
                            <th>شحنات</th>
                            <th>الحالة</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($manifests as $manifest)
                            <tr>
                                <td>
                                    <a href="{{ route('manifests.show', $manifest) }}"
                                       class="num font-semibold hover:underline">{{ $manifest->code }}</a>
                                </td>
                                <td class="text-ink-600">{{ $manifest->fromHub?->name }} ← {{ $manifest->toHub?->name }}</td>
                                <td class="max-w-40 truncate text-ink-600">
                                    {{ $manifest->driver_name ?: '—' }}
                                    @if ($manifest->vehicle_number)
                                        <span class="num text-xs text-ink-500">· {{ $manifest->vehicle_number }}</span>
                                    @endif
                                </td>
                                <td class="num">{{ number_format($manifest->bags_count) }}</td>
                                <td class="num">{{ number_format($manifest->shipments_count) }}</td>
                                <td>
                                    <span class="chip {{ $manifest->statusTone() }}">{{ $manifest->statusLabel() }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-10 text-center text-ink-500">لا كشوف في هذه الحالة.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($manifests->hasPages())
                <div class="border-t border-ink-100 px-5 py-4">{{ $manifests->links() }}</div>
            @endif
        </section>
    </div>

    <section class="card h-fit p-5">
        <h2 class="card-title">كشف جديد</h2>
        <form method="POST" action="{{ route('manifests.store') }}" class="mt-3 space-y-3">
            @csrf
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="field-label" for="from_hub_id">من</label>
                    <select id="from_hub_id" name="from_hub_id" class="field-input" required>
                        @foreach ($hubs as $hub)
                            <option value="{{ $hub->id }}" @selected($home?->id === $hub->id)>{{ $hub->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="field-label" for="to_hub_id">إلى</label>
                    <select id="to_hub_id" name="to_hub_id" class="field-input" required>
                        @foreach ($hubs as $hub)
                            <option value="{{ $hub->id }}"
                                    @selected($away?->id === $hub->id)>
                                {{ $hub->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('to_hub_id') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label class="field-label" for="driver_name">السائق</label>
                <input id="driver_name" name="driver_name" type="text" maxlength="160" class="field-input"
                       value="{{ old('driver_name') }}">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="field-label" for="driver_phone">هاتفه</label>
                    <input id="driver_phone" name="driver_phone" type="text" maxlength="20" class="field-input num"
                           value="{{ old('driver_phone') }}">
                </div>
                <div>
                    <label class="field-label" for="vehicle_number">رقم المركبة</label>
                    <input id="vehicle_number" name="vehicle_number" type="text" maxlength="40" class="field-input"
                           value="{{ old('vehicle_number') }}">
                </div>
            </div>
            <button type="submit" class="btn-primary w-full" @disabled($hubs->count() < 2)>أنشئ الكشف</button>
        </form>
    </section>
</div>
@endsection
