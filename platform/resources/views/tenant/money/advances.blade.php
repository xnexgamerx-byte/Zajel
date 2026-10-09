@extends('layouts.app')
@section('title', 'سلف التجّار')

@section('content')
<div class="mb-5">
    <h1 class="page-title">سلف التجّار</h1>
    <p class="mt-1 text-sm text-ink-500">
        سلفةٌ تُعطى للتاجر من صندوق، وتُستردّ وحدها من مستحقّاته: كل كشفٍ يُقفَل له يُخصم منه ما بقي من سلفه، الأقدم أوّلاً،
        حتى تُسدَّد. ويستطيع أن يسدّدها نقداً متى شاء.
    </p>
</div>

<div class="mb-5 grid grid-cols-2 gap-3 sm:gap-4">
    <div class="stat">
        <span class="stat-label">باقٍ على التجّار</span>
        <span class="stat-value num text-warn-700">{{ number_format($outstanding) }}</span>
    </div>
    <div class="stat">
        <span class="stat-label">تجّار عليهم سلف</span>
        <span class="stat-value num">{{ number_format($debtors) }}</span>
    </div>
</div>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <div class="space-y-5 lg:col-span-2">
        <form method="GET" action="{{ route('merchant-advances.index') }}" role="search" class="card flex flex-wrap items-end gap-3 p-4">
            <div class="min-w-48 flex-1">
                <label class="field-label" for="q">التاجر</label>
                <input id="q" name="q" type="search" value="{{ $filters['q'] }}" class="field-input" placeholder="اسمه أو رمزه أو هاتفه">
            </div>
            <div>
                <label class="field-label" for="status">الحال</label>
                <select id="status" name="status" class="field-input">
                    @foreach (['open' => 'لم تُسدَّد', 'repaid' => 'سُدِّدت', 'all' => 'الكل'] as $value => $label)
                        <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn-primary">ابحث</button>
        </form>

        <section class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th>السلفة</th>
                            <th>التاجر</th>
                            <th>المبلغ</th>
                            <th>استُردّ</th>
                            <th>باقٍ</th>
                            <th>أُعطيت</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($advances as $advance)
                            <tr>
                                <td class="whitespace-nowrap">
                                    <span class="num font-semibold">{{ $advance->number }}</span>
                                    <span class="chip {{ ['open' => 'chip-warn', 'repaid' => 'chip-ok'][$advance->status] ?? 'chip-mute' }} mt-0.5 block w-fit">
                                        {{ ['open' => 'لم تُسدَّد', 'repaid' => 'سُدِّدت', 'cancelled' => 'ملغاة'][$advance->status] ?? $advance->status }}
                                    </span>
                                </td>
                                <td>
                                    <a href="{{ route('merchant-advances.index', ['q' => $advance->merchant?->code, 'status' => 'all']) }}"
                                       class="font-medium hover:underline">{{ $advance->merchant?->business_name }}</a>
                                    @if ($advance->note)
                                        <span class="block text-xs text-ink-500">{{ $advance->note }}</span>
                                    @endif
                                </td>
                                <td class="num">{{ number_format($advance->amount) }}</td>
                                <td>
                                    <span class="num">{{ number_format($advance->recovered) }}</span>
                                    {{-- من أين استُردّت: الكشوف بأرقامها، والنقد بصندوقه --}}
                                    @foreach ($advance->recoveries as $recovery)
                                        <span class="block whitespace-nowrap text-xs text-ink-500">
                                            <span class="num">{{ number_format($recovery->amount) }}</span>
                                            @if ($recovery->settlement)
                                                من الكشف
                                                <a href="{{ route('settlements.merchants.show', $recovery->settlement) }}" class="num underline">{{ $recovery->settlement->code }}</a>
                                            @else
                                                نقداً
                                            @endif
                                        </span>
                                    @endforeach
                                </td>
                                <td class="num font-semibold {{ $advance->remaining() ? 'text-warn-700' : 'text-ok-700' }}">{{ number_format($advance->remaining()) }}</td>
                                <td class="whitespace-nowrap text-xs text-ink-500">
                                    <span class="num">{{ $advance->created_at?->format('Y-m-d') }}</span>
                                    <span class="block">{{ $advance->user?->name }} · {{ $advance->cashBox?->name }}</span>
                                    {{-- أُعطيت خطأً: تُلغى في يومها ما لم يُستردّ منها شيء (docs/plan/38) --}}
                                    @if ($advance->status === 'open' && ! $advance->recovered && \App\Actions\Money\UndoWithinDay::open($advance->created_at))
                                        <details class="relative mt-1">
                                            <summary class="cursor-pointer text-bad-700 underline">إلغاء</summary>
                                            <form method="POST" action="{{ route('merchant-advances.cancel', $advance) }}"
                                                  class="absolute end-0 z-20 mt-1 w-60 space-y-2 rounded-2xl border border-ink-200 bg-white p-3 shadow-lg">
                                                @csrf
                                                <input name="reason" type="text" required maxlength="255" class="field-input text-sm" placeholder="سبب الإلغاء">
                                                <button type="submit" class="btn-danger w-full text-sm">ألغِ السلفة</button>
                                            </form>
                                        </details>
                                    @elseif ($advance->status === 'cancelled')
                                        <span class="block text-bad-700">أُلغيت: {{ $advance->cancel_reason }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-10 text-center text-ink-500">لا سلف بهذا البحث.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($advances->hasPages())
                <div class="border-t border-ink-100 px-5 py-4">{{ $advances->links() }}</div>
            @endif
        </section>
    </div>

    <div class="space-y-5">
        @if ($boxes->isEmpty())
            <section class="card p-5 text-sm text-ink-500">لا صندوق تدفع منه: اطلب ربطك بصندوق من «الصندوق».</section>
        @else
            <form method="POST" action="{{ route('merchant-advances.store') }}" class="card space-y-3 p-5">
                @csrf
                <h2 class="card-title">أعطِ سلفة</h2>
                <div>
                    <label class="field-label" for="merchant_id">التاجر</label>
                    <select id="merchant_id" name="merchant_id" class="field-input" data-searchable required>
                        <option value="">اختر</option>
                        @foreach ($merchants as $merchant)
                            <option value="{{ $merchant->id }}" @selected((int) old('merchant_id') === $merchant->id)>
                                {{ $merchant->business_name }} ({{ $merchant->code }})
                            </option>
                        @endforeach
                    </select>
                    @error('merchant_id') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="field-label" for="amount">المبلغ</label>
                    <input id="amount" name="amount" type="number" min="1" step="1" required class="field-input num" value="{{ old('amount') }}">
                    @error('amount') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="field-label" for="cash_box_id">يخرج من</label>
                    <select id="cash_box_id" name="cash_box_id" class="field-input" required>
                        @foreach ($boxes as $box)
                            <option value="{{ $box->id }}" @selected((int) old('cash_box_id', $defaultBox) === $box->id)>
                                {{ $box->name }} ({{ number_format($box->balance) }})
                            </option>
                        @endforeach
                    </select>
                    @error('cash_box_id') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="field-label" for="note">ملاحظة</label>
                    <input id="note" name="note" type="text" maxlength="255" class="field-input" value="{{ old('note') }}"
                           placeholder="سببها أو اتّفاق السداد">
                </div>
                <button type="submit" class="btn-primary w-full">أعطِ السلفة</button>
                <p class="text-xs text-ink-500">تُقيَّد على التاجر في كشف حسابه، وتُخصم من أوّل كشفٍ يُقفَل له.</p>
            </form>

            <form method="POST" action="{{ route('merchant-advances.repay') }}" class="card space-y-3 p-5">
                @csrf
                <h2 class="card-title">سداد نقديّ من التاجر</h2>
                <div>
                    <label class="field-label" for="repay_merchant_id">التاجر</label>
                    <select id="repay_merchant_id" name="merchant_id" class="field-input" data-searchable required>
                        <option value="">اختر</option>
                        @foreach ($merchants as $merchant)
                            <option value="{{ $merchant->id }}">{{ $merchant->business_name }} ({{ $merchant->code }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="field-label" for="repay_amount">المبلغ</label>
                    <input id="repay_amount" name="repay_amount" type="number" min="1" step="1" required class="field-input num">
                    @error('repay_amount') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="field-label" for="repay_box">يدخل في</label>
                    <select id="repay_box" name="cash_box_id" class="field-input" required>
                        @foreach ($boxes as $box)
                            <option value="{{ $box->id }}" @selected($defaultBox === $box->id)>{{ $box->name }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="btn-ghost w-full">سجّل السداد</button>
            </form>
        @endif
    </div>
</div>
@endsection
