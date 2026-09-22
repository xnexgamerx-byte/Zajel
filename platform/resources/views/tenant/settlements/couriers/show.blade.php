@extends('layouts.app')
@section('title', 'كشف ' . $settlement->code)

@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <div class="flex items-center gap-3">
            <h1 class="font-mono text-2xl font-bold" dir="ltr">{{ $settlement->code }}</h1>
            <x-settlement-status :status="$settlement->status" />
        </div>
        <p class="mt-1 text-sm text-slate-500">
            {{ $settlement->courier->name }}
            · من {{ $settlement->from_date?->format('Y-m-d') }}
            إلى {{ $settlement->to_date?->format('Y-m-d') }}
        </p>
    </div>
    <a href="{{ route('settlements.couriers.index') }}" class="btn-ghost">رجوع</a>
</div>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <div class="lg:col-span-2">
        <section class="card overflow-hidden">
            <h2 class="border-b border-slate-100 px-5 py-4 text-sm font-bold">
                سطور الكشف — {{ number_format($settlement->shipments_count) }} شحنة
            </h2>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-4 py-3 text-start font-semibold">رقم الوصل</th>
                            <th class="px-4 py-3 text-start font-semibold">المستلم</th>
                            <th class="px-4 py-3 text-start font-semibold">الوجهة</th>
                            <th class="px-4 py-3 text-start font-semibold">الحالة</th>
                            <th class="px-4 py-3 text-start font-semibold">المحصَّل</th>
                            <th class="px-4 py-3 text-start font-semibold">عمولته</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($settlement->lines as $line)
                            <tr>
                                <td class="px-4 py-2.5">
                                    <a href="{{ route('shipments.show', $line->shipment) }}"
                                       class="font-mono font-semibold text-brand-700 hover:underline" dir="ltr">
                                        {{ $line->shipment->number }}
                                    </a>
                                </td>
                                <td class="px-4 py-2.5">{{ $line->shipment->recipient_name }}</td>
                                <td class="px-4 py-2.5 text-slate-600">
                                    {{ $line->shipment->governorate->name_ar }}
                                </td>
                                <td class="px-4 py-2.5">
                                    <x-status-badge :status="$line->shipment->status" />
                                </td>
                                <td class="px-4 py-2.5 font-semibold" dir="ltr">
                                    {{ number_format($line->collected_amount) }}
                                </td>
                                <td class="px-4 py-2.5 text-emerald-700" dir="ltr">
                                    {{ number_format($line->commission) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-slate-50 font-bold">
                        <tr>
                            <td class="px-4 py-3" colspan="4">المجموع</td>
                            <td class="px-4 py-3" dir="ltr">{{ number_format($settlement->cod_total) }}</td>
                            <td class="px-4 py-3 text-emerald-700" dir="ltr">
                                {{ number_format($settlement->commission_total) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500">
                السطور لقطة مُجمَّدة وقت فتح الكشف — تغيير تسعيرة لاحقاً لا يمسّها.
            </p>
        </section>
    </div>

    <div class="space-y-5">
        <section class="card p-5">
            <h2 class="mb-4 text-sm font-bold">الحساب</h2>
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between">
                    <dt class="text-slate-600">المحصَّل من الزبائن</dt>
                    <dd class="font-semibold" dir="ltr">{{ number_format($settlement->cod_total) }}</dd>
                </div>
                <div class="flex justify-between text-emerald-700">
                    <dt>عمولة المندوب</dt>
                    <dd class="font-semibold" dir="ltr">−{{ number_format($settlement->commission_total) }}</dd>
                </div>
                @if ($settlement->deductions)
                    <div class="flex justify-between text-red-600">
                        <dt>خصومات عليه</dt>
                        <dd class="font-semibold" dir="ltr">+{{ number_format($settlement->deductions) }}</dd>
                    </div>
                @endif
                <div class="flex justify-between border-t-2 border-slate-300 pt-2">
                    <dt class="font-bold">الواجب تسليمه</dt>
                    <dd class="text-lg font-bold text-brand-700" dir="ltr">
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
                        <input id="deductions" name="deductions" type="number" min="0" step="250"
                               value="{{ old('deductions', 0) }}" class="field-input pe-12 text-left" dir="ltr">
                        <span class="absolute inset-y-0 end-3 flex items-center text-xs text-slate-400">د.ع</span>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">تلف، غرامة، أو نقص — تزيد ما يسلّمه.</p>
                    @error('deductions') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="field-label" for="notes">ملاحظات</label>
                    <textarea id="notes" name="notes" rows="2" class="field-input">{{ old('notes') }}</textarea>
                </div>

                <div class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 ring-1 ring-amber-200">
                    بعد التأكيد يُقفَل الكشف ولا يُعدَّل. أي تصحيح يكون بحركة معاكسة في الدفتر.
                </div>

                <button class="btn-primary w-full">تأكيد استلام النقد وإقفال الكشف</button>
            </form>
        @else
            <section class="card p-5">
                <h2 class="mb-3 text-sm font-bold">الإقفال</h2>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-slate-500">أُقفِل في</dt>
                        <dd class="font-medium" dir="ltr">{{ $settlement->confirmed_at?->format('Y-m-d H:i') }}</dd>
                    </div>
                    @if ($settlement->notes)
                        <div class="border-t border-slate-100 pt-2">
                            <dt class="mb-1 text-slate-500">ملاحظات</dt>
                            <dd class="text-slate-700">{{ $settlement->notes }}</dd>
                        </div>
                    @endif
                </dl>
            </section>
        @endif
    </div>
</div>
@endsection
