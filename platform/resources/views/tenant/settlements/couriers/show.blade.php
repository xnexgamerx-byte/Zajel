@extends('layouts.app')
@section('title', 'كشف ' . $settlement->code)

@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <div class="flex items-center gap-3">
            <h1 class="page-title font-mono" dir="ltr">{{ $settlement->code }}</h1>
            <x-settlement-status :status="$settlement->status" />
        </div>
        <p class="mt-1 text-sm text-ink-500">
            {{ $settlement->courier->name }}
            · من {{ $settlement->from_date?->format('Y-m-d') }}
            إلى {{ $settlement->to_date?->format('Y-m-d') }}
        </p>
    </div>
    <a href="{{ route('settlements.couriers.index') }}" class="btn-ghost">رجوع</a>
</div>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <div class="space-y-5 lg:col-span-2">
        <section class="card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-ink-100 px-5 py-4">
                <h2 class="text-sm font-bold">
                    سطور الكشف — {{ \App\Support\Arabic::shipments((int) $settlement->shipments_count) }}
                </h2>
                @if ($editable)
                    {{-- المسودّة تُعدَّل: تُحدَّد شحناتٌ فتُخرَج، وتبقى بلا تسوية للكشف التالي --}}
                    <form method="POST" action="{{ route('settlements.couriers.lines.remove', $settlement) }}" id="remove-lines">@csrf</form>
                    <button type="submit" form="remove-lines" class="btn-danger"
                            data-confirm="تُخرَج الشحنات المحدَّدة من كشف {{ $settlement->code }}؟ تبقى بلا تسوية وتدخل الكشف التالي.">
                        إخراج المحدَّد من الكشف
                    </button>
                @endif
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr>
                            @if ($editable)
                                <th class="w-10">
                                    <input type="checkbox" aria-label="تحديد الكل" class="size-4 accent-[var(--brand)]" data-check-all-in="table">
                                </th>
                            @endif
                            <th >رقم الوصل</th>
                            <th >المستلم</th>
                            <th >الوجهة</th>
                            <th >الحالة</th>
                            <th >المحصَّل</th>
                            <th >عمولته</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-100">
                        @foreach ($lines as $line)
                            <tr>
                                @if ($editable)
                                    <td class="px-4 py-2.5">
                                        <input type="checkbox" name="shipment_ids[]" value="{{ $line->shipment_id }}" form="remove-lines"
                                               aria-label="الوصل {{ $line->shipment->number }}" class="size-4 accent-[var(--brand)]">
                                    </td>
                                @endif
                                <td class="px-4 py-2.5">
                                    <a href="{{ route('shipments.show', $line->shipment) }}"
                                       class="font-mono font-semibold text-[var(--brand)] hover:underline" dir="ltr">
                                        {{ $line->shipment->number }}
                                    </a>
                                </td>
                                <td class="px-4 py-2.5">{{ $line->shipment->recipient_name }}</td>
                                <td class="px-4 py-2.5 text-ink-600">
                                    {{ $line->shipment->governorate->name_ar }}
                                </td>
                                <td class="px-4 py-2.5">
                                    <x-status-badge :status="$line->shipment->status" />
                                </td>
                                <td class="px-4 py-2.5 font-semibold" dir="ltr">
                                    {{ number_format($line->collected_amount) }}
                                </td>
                                <td class="px-4 py-2.5 text-ok-700" dir="ltr">
                                    {{ number_format($line->commission) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-ink-50 font-bold">
                        <tr>
                            <td class="px-4 py-3" colspan="{{ $editable ? 5 : 4 }}">المجموع</td>
                            <td class="px-4 py-3" dir="ltr">{{ number_format($settlement->cod_total) }}</td>
                            <td class="px-4 py-3 text-ok-700" dir="ltr">
                                {{ number_format($settlement->commission_total) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            @if ($lines->hasPages())
                <div class="border-t border-ink-100 p-4">{{ $lines->links() }}</div>
            @endif

            <p class="border-t border-ink-100 px-5 py-3 text-xs text-ink-500">
                السطور لقطة مُجمَّدة وقت دخولها الكشف — تغيير تسعيرة لاحقاً لا يمسّها.
            </p>
        </section>

        @if ($editable)
            <x-settlement-addable :shipments="$addable" :count="$addableCount" party="courier"
                                  :action="route('settlements.couriers.lines.add', $settlement)" />
        @endif
    </div>

    <div class="space-y-5">
        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">الحساب</h2>
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between">
                    <dt class="text-ink-600">المحصَّل من الزبائن</dt>
                    <dd class="font-semibold" dir="ltr">{{ number_format($settlement->cod_total) }}</dd>
                </div>
                <div class="flex justify-between text-ok-700">
                    <dt>عمولة المندوب</dt>
                    <dd class="font-semibold" dir="ltr">−{{ number_format($settlement->commission_total) }}</dd>
                </div>
                @if ($settlement->deductions)
                    <div class="flex justify-between text-bad-700">
                        <dt>خصومات عليه</dt>
                        <dd class="font-semibold" dir="ltr">+{{ number_format($settlement->deductions) }}</dd>
                    </div>
                @endif
                <div class="flex justify-between border-t-2 border-ink-300 pt-2">
                    <dt class="font-bold">الواجب تسليمه</dt>
                    <dd class="text-lg font-bold text-[var(--brand)]" dir="ltr">
                        {{ number_format($settlement->net_amount) }} د.ع
                    </dd>
                </div>
            </dl>
        </section>

        @if ($settlement->status === 'draft')
            <form method="POST" action="{{ route('settlements.couriers.confirm', $settlement) }}"
                  class="card space-y-4 p-5">
                @csrf
                <h2 class="text-sm font-bold">استلام النقد</h2>

                <div>
                    <label class="field-label" for="deductions">خصومات عليه</label>
                    <div class="relative">
                        <input id="deductions" name="deductions" type="number" min="0" step="1"
                               value="{{ old('deductions', 0) }}" class="field-input ps-12 text-left" dir="ltr">
                        <span class="absolute inset-y-0 end-3 flex items-center text-xs text-ink-400">د.ع</span>
                    </div>
                    <p class="mt-1 text-xs text-ink-500">تلف، غرامة، أو نقص — تزيد ما يسلّمه.</p>
                    @error('deductions') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="field-label" for="notes">ملاحظات</label>
                    <textarea id="notes" name="notes" rows="2" class="field-input">{{ old('notes') }}</textarea>
                </div>

                <div class="rounded-lg bg-warn-50 px-3 py-2 text-xs text-warn-700 ring-1 ring-warn-200">
                    بعد التأكيد يُقفَل الكشف ولا يُعدَّل. أي تصحيح يكون بحركة معاكسة في الدفتر.
                </div>

                <button type="submit" class="btn-primary w-full">تأكيد استلام النقد وإقفال الكشف</button>
            </form>

            @can('money.settle')
                {{-- بُني خطأً أو يُبنى من جديد: المسودّة لا أثر لها في الحساب --}}
                <form method="POST" action="{{ route('settlements.couriers.destroy', $settlement) }}" class="card p-5">
                    @csrf
                    @method('DELETE')
                    <p class="text-xs text-ink-500">
                        بُني خطأً أو تريد بناءه من جديد؟ الحذف لا يغيّر شيئاً في الحسابات، وشحناته تدخل الكشف التالي كما هي.
                    </p>
                    <button type="submit" class="btn-danger mt-3 w-full"
                            data-confirm="يُحذف كشف {{ $settlement->code }}؟ لا يتغيّر شيءٌ في الحسابات، وشحناته تدخل الكشف التالي كما هي.">
                        حذف الكشف
                    </button>
                </form>
            @endcan
        @else
            <section class="card p-5">
                <h2 class="mb-3 text-sm font-bold">الإقفال</h2>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-ink-500">أُقفِل في</dt>
                        <dd class="font-medium" dir="ltr">{{ $settlement->confirmed_at?->format('Y-m-d H:i') }}</dd>
                    </div>
                    @if ($settlement->notes)
                        <div class="border-t border-ink-100 pt-2">
                            <dt class="mb-1 text-ink-500">ملاحظات</dt>
                            <dd class="text-ink-700">{{ $settlement->notes }}</dd>
                        </div>
                    @endif
                </dl>
            </section>
        @endif
    </div>
</div>
@endsection
