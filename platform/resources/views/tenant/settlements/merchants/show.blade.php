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
            {{ $settlement->merchant->business_name }}
            · من {{ $settlement->from_date?->format('Y-m-d') }}
            إلى {{ $settlement->to_date?->format('Y-m-d') }}
        </p>
    </div>
    <a href="{{ route('settlements.merchants.index') }}" class="btn-ghost">رجوع</a>
</div>

<div @class(['grid grid-cols-1 gap-5 lg:grid-cols-3', 'pb-20' => $editable])>
    <div class="space-y-5 lg:col-span-2">
        <section class="card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-ink-100 px-5 py-4">
                <h2 class="text-sm font-bold">
                    سطور الكشف — {{ \App\Support\Arabic::shipments((int) $settlement->shipments_count) }}
                    @if ($settlement->returned_count)
                        <span class="font-normal text-warn-700">
                            (منها {{ $settlement->returned_count }} راجعة)
                        </span>
                    @endif
                </h2>
                @if ($editable)
                    {{-- ما يُحدَّد يظهر أسفل الشاشة بعدده وصافيه و«حاسب التاجر على المحدَّد» --}}
                    <p class="text-xs text-ink-500">حدّد شحناتٍ لتحاسب التاجر عليها وحدها، وتبقى البقية في المسودّة.</p>
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
                            <th >الحالة</th>
                            <th >المحصَّل</th>
                            <th >التوصيل</th>
                            <th >الراجع</th>
                            <th >له</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-100">
                        @foreach ($lines as $line)
                            <tr>
                                @if ($editable)
                                    <td class="px-4 py-2.5">
                                        <input type="checkbox" name="shipment_ids[]" value="{{ $line->shipment_id }}" form="settle"
                                               data-net="{{ (int) $line->net_amount }}"
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
                                <td class="px-4 py-2.5"><x-status-badge :status="$line->shipment->status" /></td>
                                <td class="px-4 py-2.5 font-semibold" dir="ltr">
                                    {{ number_format($line->collected_amount) }}
                                </td>
                                <td class="px-4 py-2.5 text-ink-600" dir="ltr">
                                    {{ $line->delivery_fee ? '−'.number_format($line->delivery_fee) : '' }}
                                </td>
                                <td class="px-4 py-2.5 text-warn-700" dir="ltr">
                                    {{ $line->return_fee ? '−'.number_format($line->return_fee) : '' }}
                                </td>
                                <td class="px-4 py-2.5 font-bold {{ $line->net_amount >= 0 ? '' : 'text-bad-700' }}"
                                    dir="ltr">{{ number_format($line->net_amount) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-ink-50 font-bold">
                        <tr>
                            <td class="px-4 py-3" colspan="{{ $editable ? 4 : 3 }}">المجموع</td>
                            <td class="px-4 py-3" dir="ltr">{{ number_format($settlement->cod_total) }}</td>
                            <td class="px-4 py-3" dir="ltr">
                                {{ $settlement->delivery_fees_total ? '−'.number_format($settlement->delivery_fees_total) : '' }}
                            </td>
                            <td class="px-4 py-3 text-warn-700" dir="ltr">
                                {{ $settlement->return_fees_total ? '−'.number_format($settlement->return_fees_total) : '' }}
                            </td>
                            {{-- مجموع السطور: ما اقتُطع لسلف التاجر يُطرح منه في «الحساب» --}}
                            <td class="px-4 py-3 text-[var(--brand)]" dir="ltr">{{ number_format($settlement->net_amount + $settlement->advance_deduction) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            @if ($lines->hasPages())
                <div class="border-t border-ink-100 p-4">{{ $lines->links() }}</div>
            @endif
        </section>

        @if ($editable)
            <x-settlement-addable :shipments="$addable" :count="$addableCount" party="merchant"
                                  :action="route('settlements.merchants.lines.add', $settlement)" />
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
                <div class="flex justify-between">
                    <dt class="text-ink-600">أجور التوصيل</dt>
                    <dd dir="ltr">−{{ number_format($settlement->delivery_fees_total) }}</dd>
                </div>
                @if ($settlement->cod_fees_total)
                    <div class="flex justify-between">
                        <dt class="text-ink-600">عمولة التحصيل</dt>
                        <dd dir="ltr">−{{ number_format($settlement->cod_fees_total) }}</dd>
                    </div>
                @endif
                @if ($settlement->return_fees_total)
                    <div class="flex justify-between text-warn-700">
                        <dt>أجور الرواجع</dt>
                        <dd dir="ltr">−{{ number_format($settlement->return_fees_total) }}</dd>
                    </div>
                @endif
                @if ($settlement->advance_deduction)
                    <div class="flex justify-between text-warn-700">
                        <dt>خصم سلفة</dt>
                        <dd dir="ltr">−{{ number_format($settlement->advance_deduction) }}</dd>
                    </div>
                @endif
                <div class="flex justify-between border-t-2 border-ink-300 pt-2">
                    <dt class="font-bold">{{ $settlement->net_amount >= 0 ? 'الواجب دفعه له' : 'الواجب تحصيله منه' }}</dt>
                    <dd class="text-lg font-bold {{ $settlement->net_amount >= 0 ? 'text-[var(--brand)]' : 'text-bad-700' }}"
                        dir="ltr">{{ number_format(abs($settlement->net_amount)) }} د.ع</dd>
                </div>
            </dl>
        </section>

        @if ($settlement->status === 'draft')
            <form method="POST" action="{{ route('settlements.merchants.confirm', $settlement) }}" id="settle"
                  class="card space-y-4 p-5">
                @csrf
                <h2 class="text-sm font-bold">إقفال الكشف</h2>
                <p class="text-xs text-ink-500">
                    يُثبَّت الرقم وتُوسَم الشحنات فلا تدخل كشفاً آخر. الدفع خطوة تالية.
                </p>
                @php $owed = \App\Actions\Cash\MerchantAdvances::outstanding($settlement->merchant_id); @endphp
                @if ($owed > 0)
                    {{-- السلفة تُقتطع عند الإقفال بقدر صافي الكشف (docs/plan/38) --}}
                    <p class="rounded-lg bg-warn-50 px-3 py-2 text-xs text-warn-700 ring-1 ring-warn-200">
                        على التاجر سلفٌ باقية <span class="num">{{ number_format($owed) }}</span> د.ع: يُخصم منها عند الإقفال
                        <span class="num">{{ number_format(min($owed, max(0, (int) $settlement->net_amount))) }}</span> د.ع.
                    </p>
                @endif
                @if ($editable)
                    <p class="rounded-lg bg-info-50 px-3 py-2 text-xs text-info-700 ring-1 ring-info-200" data-picked-when="settle" hidden>
                        حدّدت شحنات: يُقفَل بها وحدها كشفٌ جديد تدفعه من صفحته، وتبقى البقية في هذه المسودّة.
                    </p>
                @endif
                <div>
                    <label class="field-label" for="notes">ملاحظات</label>
                    <textarea id="notes" name="notes" rows="2" class="field-input">{{ old('notes') }}</textarea>
                </div>
                <button type="submit" class="btn-primary w-full">
                    <span data-picked-unless="settle">إقفال الكشف</span>
                    @if ($editable)
                        <span data-picked-when="settle" hidden>حاسب التاجر على المحدَّد</span>
                    @endif
                </button>
            </form>

            @can('money.settle')
                {{-- بُني خطأً أو يُبنى من جديد: المسودّة لا أثر لها في الحساب --}}
                <form method="POST" action="{{ route('settlements.merchants.destroy', $settlement) }}" class="card p-5">
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
        @elseif ($settlement->status === 'confirmed')
            <form method="POST" action="{{ route('settlements.merchants.pay', $settlement) }}"
                  class="card space-y-4 p-5">
                @csrf
                <h2 class="text-sm font-bold">تسجيل الدفع</h2>

                <div>
                    <label class="field-label" for="payout_method">طريقة الدفع</label>
                    <select id="payout_method" name="payout_method" class="field-input" required>
                        @foreach (['cash' => 'نقد', 'zaincash' => 'زين كاش', 'asiahawala' => 'آسيا حوالة',
                                   'fastpay' => 'فاست باي', 'qi' => 'Qi كارد', 'fib' => 'FIB',
                                   'bank_transfer' => 'حوالة مصرفية'] as $value => $label)
                            <option value="{{ $value }}"
                                    @selected(old('payout_method', $settlement->payout_method) === $value)>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                    @error('payout_method') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="field-label" for="payout_reference">رقم الحوالة / الإيصال</label>
                    <input id="payout_reference" name="payout_reference" class="field-input text-left" dir="ltr"
                           value="{{ old('payout_reference') }}">
                    @error('payout_reference') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                {{-- النقد يخرج من صندوق: لا يُدفع منه أكثر ممّا فيه (CashBook) --}}
                @if ($boxes->isNotEmpty())
                    @php
                        $from = $boxes->firstWhere('id', (int) old('cash_box_id', $defaultBox?->id)) ?? $boxes->first();
                        $due = (int) $settlement->net_amount;
                    @endphp
                    <div>
                        <label class="field-label" for="cash_box_id">يُدفع النقد من</label>
                        <select id="cash_box_id" name="cash_box_id" class="field-input">
                            @foreach ($boxes as $item)
                                <option value="{{ $item->id }}" @selected($from->id === $item->id)>
                                    {{ $item->name }} ({{ number_format($item->balance) }})
                                </option>
                            @endforeach
                        </select>
                        <p class="field-hint">للدفع النقدي وحده. الحوالات لا تُخرج شيئاً من الصندوق.</p>
                        @error('cash_box_id') <p class="field-error">{{ $message }}</p> @enderror
                    </div>

                    @if ($due > 0 && (int) $from->balance < $due)
                        <div class="alert alert-warn text-sm" role="status">
                            <x-icon name="alert" class="size-5 shrink-0"/>
                            <p>
                                رصيد «{{ $from->name }}» <span class="num">{{ number_format($from->balance) }}</span> د.ع،
                                ومبلغ الكشف <span class="num">{{ number_format($due) }}</span> د.ع: لا يُدفع نقداً منه حتى
                                تُحصَّل المبالغ من المندوبين. اختر صندوقاً فيه المبلغ، أو ادفع بحوالة.
                            </p>
                        </div>
                    @endif
                @endif

                <button type="submit" class="btn-primary w-full">سجّل الدفع</button>
            </form>
        @else
            <section class="card p-5">
                <h2 class="mb-3 text-sm font-bold">الدفع</h2>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-ink-500">الطريقة</dt>
                        <dd class="font-medium">
                            {{ ['cash' => 'نقد', 'zaincash' => 'زين كاش', 'asiahawala' => 'آسيا حوالة',
                                'fastpay' => 'فاست باي', 'qi' => 'Qi كارد', 'fib' => 'FIB',
                                'bank_transfer' => 'حوالة مصرفية'][$settlement->payout_method] ?? '—' }}
                        </dd>
                    </div>
                    @if ($settlement->payout_reference)
                        <div class="flex justify-between">
                            <dt class="text-ink-500">المرجع</dt>
                            <dd class="font-medium" dir="ltr">{{ $settlement->payout_reference }}</dd>
                        </div>
                    @endif
                    <div class="flex justify-between">
                        <dt class="text-ink-500">دُفع في</dt>
                        <dd class="font-medium" dir="ltr">{{ $settlement->paid_at?->format('Y-m-d H:i') }}</dd>
                    </div>
                </dl>
            </section>
        @endif
    </div>
</div>

@if ($editable)
    {{-- ما حُدِّد: يُحاسَب عليه، أو يُضاف إلى الكشف. والشريطان في حاويةٍ واحدة فلا يتراكبان --}}
    <div class="fixed inset-x-0 bottom-0 z-40">
        <x-picked-bar form="settle" :total="(int) $settlement->shipments_count" all="كل شحنات الكشف" net-label="صافيه للتاجر">
            <button type="submit" form="settle" class="btn-primary h-9 px-4 text-sm"
                    data-confirm-some="المحدَّد {count}: يُقفَل بها كشفٌ جديد للتاجر صافيه {net} د.ع، تدفعه من صفحته. وتبقى البقية في المسودّة {{ $settlement->code }}."
                    data-confirm-all="حدّدت كل شحنات الكشف: يُقفَل كشف {{ $settlement->code }} كلّه، صافيه {net} د.ع.">
                حاسب التاجر على المحدَّد
            </button>
        </x-picked-bar>
        <x-picked-bar form="add-lines">
            <button type="submit" form="add-lines" class="btn-primary h-9 px-4 text-sm">أضِف المحدَّد للكشف</button>
        </x-picked-bar>
    </div>
@endif
@endsection
