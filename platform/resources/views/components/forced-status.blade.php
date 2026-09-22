@props(['shipment'])

@php
    // الخيارات هنا هي ما يخرج عن المسار: لو كانت مسموحة لَما احتاجت إجباراً
    $all = collect(\App\Enums\ShipmentStatus::cases())
        ->reject(fn ($s) => $s === $shipment->status || $shipment->status->canMoveTo($s));
@endphp

@if ($all->isNotEmpty())
    <details class="mt-4">
        <summary class="cursor-pointer text-sm font-semibold text-warn-700">
            تغيير إجباري خارج المسار
        </summary>

        <form method="POST" action="{{ route('shipments.status', $shipment) }}" class="mt-3 space-y-3">
            @csrf
            <input type="hidden" name="force" value="1">

            <div class="rounded-lg bg-warn-50 px-3 py-2 text-xs text-warn-700">
                {{-- الصفحة نفسها هي الرادع: من يعلم أن اسمه يُسجَّل يفكّر مرّتين --}}
                يُسجَّل باسمك وسببه، ويظهر في تقرير «واصل إجباري».
            </div>

            <div>
                <label class="field-label" for="forced_status">الحالة الجديدة</label>
                <select id="forced_status" name="status" class="field-input" required>
                    @foreach ($all as $status)
                        <option value="{{ $status->value }}">{{ $status->label() }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="field-label" for="forced_reason">السبب <span class="text-bad-700">*</span></label>
                <input id="forced_reason" name="forced_reason" type="text" maxlength="255" required
                       class="field-input" placeholder="اتصل الزبون وأكّد الاستلام والمندوب نسي التسجيل">
                @error('forced_reason') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="btn-danger w-full">نفّذ إجبارياً</button>
        </form>
    </details>
@endif
