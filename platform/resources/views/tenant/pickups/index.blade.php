@extends('layouts.app')
@section('title', 'طلبات الاستلام')

@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="page-title">طلبات الاستلام</h1>
        <p class="mt-1 text-sm text-ink-500">تجّار جهّزوا طرودهم وينتظرون مندوب استلام.</p>
    </div>
    @if ($pending)
        <div class="card px-5 py-3 text-center">
            <div class="text-xs text-ink-500">بانتظار إسناد</div>
            <div class="text-2xl font-bold text-warn-700">{{ number_format($pending) }}</div>
        </div>
    @endif
</div>

<form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
    <div>
        <label class="field-label" for="status">الحالة</label>
        <select id="status" name="status" class="field-input">
            <option value="">المفتوحة</option>
            @foreach (['pending' => 'بانتظار إسناد', 'assigned' => 'مُسنَدة', 'in_progress' => 'قيد التنفيذ',
                       'completed' => 'مكتملة', 'cancelled' => 'ملغاة'] as $v => $l)
                <option value="{{ $v }}" @selected(request('status') === $v)>{{ $l }}</option>
            @endforeach
        </select>
    </div>
    <button type="submit" class="btn-primary">تطبيق</button>
    <a href="{{ route('pickups.index') }}" class="btn-ghost">المفتوحة</a>
</form>

<div class="space-y-3">
    @forelse ($pickups as $pickup)
        <section class="card p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="font-mono text-sm font-semibold" dir="ltr">{{ $pickup->number }}</span>
                        @php
                            [$label, $tone] = match ($pickup->status) {
                                'pending'     => ['بانتظار إسناد', 'bg-warn-50 text-warn-700 ring-warn-200'],
                                'assigned'    => ['مُسنَد', 'bg-info-50 text-info-700 ring-info-200'],
                                'in_progress' => ['قيد التنفيذ', 'bg-info-50 text-info-700 ring-info-200'],
                                'completed'   => ['مكتمل', 'bg-ok-50 text-ok-700 ring-ok-200'],
                                'cancelled'   => ['ملغى', 'bg-ink-100 text-ink-600 ring-ink-200'],
                                default       => [$pickup->status, 'bg-ink-100 text-ink-600 ring-ink-200'],
                            };
                        @endphp
                        <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 {{ $tone }}">
                            {{ $label }}
                        </span>
                    </div>
                    <div class="mt-1 text-base font-bold">{{ $pickup->merchant->business_name }}</div>
                    <div class="text-sm text-ink-600">
                        {{ $pickup->address ?: $pickup->merchant->address ?: '—' }}
                        @if ($pickup->landmark || $pickup->merchant->landmark)
                            <span class="text-[var(--brand)]">· {{ $pickup->landmark ?: $pickup->merchant->landmark }}</span>
                        @endif
                    </div>
                    <div class="mt-1 text-xs text-ink-500" dir="ltr">
                        {{ $pickup->contact_phone ?: $pickup->merchant->phone }}
                    </div>
                </div>

                <div class="text-end">
                    <div class="text-2xl font-bold">{{ $pickup->expected_count }}</div>
                    <div class="text-xs text-ink-500">طرد متوقَّع</div>
                    @if ($pickup->actual_count)
                        <div class="mt-1 text-sm font-semibold text-ok-700">
                            استُلم {{ $pickup->actual_count }}
                        </div>
                    @endif
                </div>
            </div>

            @if (in_array($pickup->status, ['pending', 'assigned', 'in_progress'], true))
                <div class="mt-4 flex flex-wrap items-end gap-3 border-t border-ink-100 pt-4">
                    <form method="POST" action="{{ route('pickups.assign', $pickup) }}"
                          class="flex flex-wrap items-end gap-3">
                        @csrf
                        <div>
                            <label class="field-label" for="courier-{{ $pickup->id }}">مندوب الاستلام</label>
                            <select id="courier-{{ $pickup->id }}" name="courier_id" class="field-input" required>
                                <option value="">اختر</option>
                                @foreach ($couriers as $courier)
                                    <option value="{{ $courier->id }}" @selected($pickup->courier_id === $courier->id)>
                                        {{ $courier->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="field-label" for="scheduled-{{ $pickup->id }}">الموعد</label>
                            <input id="scheduled-{{ $pickup->id }}" name="scheduled_at" type="date"
                                   class="field-input" value="{{ $pickup->scheduled_at?->format('Y-m-d') }}">
                        </div>
                        <button type="submit" class="btn-primary">
                            {{ $pickup->courier_id ? 'أعد الإسناد' : 'أسند' }}
                        </button>
                    </form>

                    <form method="POST" action="{{ route('pickups.cancel', $pickup) }}"
                          class="flex flex-wrap items-end gap-3">
                        @csrf
                        <div>
                            <label class="field-label" for="reason-{{ $pickup->id }}">سبب الإلغاء</label>
                            <input id="reason-{{ $pickup->id }}" name="cancel_reason" class="field-input"
                                   placeholder="مثال: التاجر أجّل">
                        </div>
                        <button type="submit"
                                class="btn-ghost text-bad-700 ring-bad-200 hover:bg-bad-50">ألغِ</button>
                    </form>
                </div>
            @elseif ($pickup->courier)
                <div class="mt-3 border-t border-ink-100 pt-3 text-sm text-ink-600">
                    المندوب: <span class="font-medium">{{ $pickup->courier->name }}</span>
                    @if ($pickup->completed_at)
                        · اكتمل <span dir="ltr">{{ $pickup->completed_at->format('Y-m-d H:i') }}</span>
                    @endif
                </div>
            @endif
        </section>
    @empty
        <div class="card p-16 text-center text-ink-500">لا طلبات استلام.</div>
    @endforelse
</div>

@if ($pickups->hasPages())
    <div class="mt-4">{{ $pickups->links() }}</div>
@endif
@endsection
