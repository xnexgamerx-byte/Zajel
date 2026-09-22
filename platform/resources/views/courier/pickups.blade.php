@extends('layouts.courier')
@section('title', 'الاستلام')

@section('content')
<h1 class="mb-3 px-1 text-sm font-bold text-ink-600">
    طلبات استلام مُسنَدة إليك ({{ $pickups->count() }})
</h1>

@forelse ($pickups as $pickup)
    <div class="mb-3 rounded-xl border border-ink-200 bg-white p-4 shadow-xs">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="truncate text-base font-bold">{{ $pickup->merchant->business_name }}</div>
                <div class="font-mono text-xs text-ink-400" dir="ltr">{{ $pickup->number }}</div>
            </div>
            <div class="shrink-0 text-end">
                <div class="text-2xl font-bold">{{ $pickup->expected_count }}</div>
                <div class="text-xs text-ink-400">طرد متوقَّع</div>
            </div>
        </div>

        <dl class="mt-3 space-y-1.5 border-t border-ink-100 pt-3 text-sm">
            <div>
                <dt class="text-xs text-ink-500">العنوان</dt>
                <dd class="font-medium">{{ $pickup->address ?: $pickup->merchant->address ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-ink-500">نقطة دالّة</dt>
                <dd class="font-bold text-[var(--brand)]">
                    {{ $pickup->landmark ?: $pickup->merchant->landmark ?: '—' }}
                </dd>
            </div>
            @if ($pickup->notes)
                <div><dt class="text-xs text-ink-500">ملاحظة</dt>
                     <dd class="font-medium">{{ $pickup->notes }}</dd></div>
            @endif
        </dl>

        <a href="tel:{{ $pickup->contact_phone ?: $pickup->merchant->phone }}"
           class="mt-3 block rounded-xl bg-ok-700 px-4 py-3 text-center text-base font-bold text-white active:brightness-110">
            اتصل بالتاجر
        </a>

        <form method="POST" action="{{ route('courier.pickups.complete', $pickup) }}" class="mt-3 space-y-2">
            @csrf
            <label class="field-label" for="actual_count-{{ $pickup->id }}">العدد المستلم فعلاً</label>
            <input id="actual_count-{{ $pickup->id }}" name="actual_count" type="number" min="0"
                   class="field-input text-left text-lg" dir="ltr"
                   value="{{ old('actual_count', $pickup->expected_count) }}" required>

            <button type="submit"
                    class="w-full rounded-xl px-4 py-3.5 text-base font-bold text-white active:opacity-90"
                    style="background: var(--brand)">
                استلمت الطرود
            </button>
            <p class="text-xs text-ink-500">
                يُسجَّل استلام كل شحنات هذا التاجر المعلّقة دفعة واحدة.
            </p>
        </form>
    </div>
@empty
    <div class="rounded-xl border border-ink-200 bg-white p-10 text-center shadow-xs">
        <p class="font-semibold text-ink-700">ما عندك طلبات استلام.</p>
    </div>
@endforelse
@endsection
