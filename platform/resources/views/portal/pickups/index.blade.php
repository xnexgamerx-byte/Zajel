@extends('layouts.portal')
@section('title', 'طلبات الاستلام')

@section('content')
<div class="mb-5">
    <h1 class="page-title">طلبات الاستلام</h1>
    <p class="mt-1 text-sm text-ink-500">اطلب مندوباً يأتي إلى متجرك ليأخذ الطرود الجاهزة.</p>
</div>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <div class="lg:col-span-2">
        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr>
                            <th >الرقم</th>
                            <th >متوقَّع</th>
                            <th >مستلَم</th>
                            <th >المندوب</th>
                            <th >الحالة</th>
                            <th >التاريخ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-100">
                        @forelse ($pickups as $pickup)
                            <tr class="hover:bg-ink-50">
                                <td class="px-4 py-3 font-mono font-semibold" dir="ltr">{{ $pickup->number }}</td>
                                <td class="px-4 py-3" dir="ltr">{{ $pickup->expected_count }}</td>
                                <td class="px-4 py-3 font-semibold" dir="ltr">{{ $pickup->actual_count ?: '—' }}</td>
                                <td class="px-4 py-3">
                                    @if ($pickup->courier)
                                        {{ $pickup->courier->name }}
                                        <div class="text-xs text-ink-500" dir="ltr">{{ $pickup->courier->phone }}</div>
                                    @else
                                        <span class="text-ink-400">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    @php
                                        [$label, $tone] = match ($pickup->status) {
                                            'pending'     => ['بانتظار مندوب', 'bg-warn-50 text-warn-700 ring-warn-200'],
                                            'assigned'    => ['أُسند', 'bg-info-50 text-info-700 ring-info-200'],
                                            'in_progress' => ['في الطريق', 'bg-info-50 text-info-700 ring-info-200'],
                                            'completed'   => ['تم', 'bg-ok-50 text-ok-700 ring-ok-200'],
                                            'cancelled'   => ['ملغى', 'bg-ink-100 text-ink-600 ring-ink-200'],
                                            default       => [$pickup->status, 'bg-ink-100 text-ink-600 ring-ink-200'],
                                        };
                                    @endphp
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 {{ $tone }}">
                                        {{ $label }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-xs text-ink-500" dir="ltr">
                                    {{ $pickup->created_at->format('Y-m-d H:i') }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-16 text-center text-ink-500">
                                    لا طلبات بعد.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($pickups->hasPages())
                <div class="border-t border-ink-100 px-4 py-3">{{ $pickups->links() }}</div>
            @endif
        </div>
    </div>

    <div>
        <form method="POST" action="{{ route('portal.pickups.store') }}" class="card space-y-4 p-5">
            @csrf
            <h2 class="text-sm font-bold">طلب جديد</h2>

            <div>
                <label class="field-label" for="expected_count">عدد الطرود <span class="text-red-500">*</span></label>
                <input id="expected_count" name="expected_count" type="number" min="1" required
                       class="field-input text-left" dir="ltr" value="{{ old('expected_count') }}">
                @error('expected_count') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="field-label" for="scheduled_at">الموعد المفضّل</label>
                <input id="scheduled_at" name="scheduled_at" type="date" class="field-input"
                       value="{{ old('scheduled_at') }}">
                @error('scheduled_at') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="field-label" for="contact_phone">هاتف التواصل</label>
                <input id="contact_phone" name="contact_phone" class="field-input text-left" dir="ltr"
                       placeholder="{{ $merchant->phone }}" value="{{ old('contact_phone') }}">
                @error('contact_phone') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="field-label" for="notes">ملاحظات</label>
                <textarea id="notes" name="notes" rows="2" class="field-input">{{ old('notes') }}</textarea>
            </div>

            <button type="submit" class="btn-primary w-full">أرسل الطلب</button>
        </form>
    </div>
</div>
@endsection
