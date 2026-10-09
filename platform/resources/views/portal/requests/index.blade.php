@extends('layouts.portal')
@section('title', 'طلباتي')

@section('content')
<div class="mb-5">
    <h1 class="page-title">طلباتي</h1>
    <p class="mt-1 text-sm text-ink-500">اطلب مستحقّاتك أو رواجعك من هنا بلا اتصال — برقمٍ تتابع حالته في هذه الصفحة.</p>
</div>

{{-- «تمّ الطلب»: رقمه ومبلغه وتاريخه (docs/plan/44) --}}
@if ($done = session('payment_request'))
    <section class="card mb-5 border-ok-200 bg-ok-50 p-5" role="status">
        <p class="text-lg font-bold text-ok-700">✓ تمّ الطلب</p>
        <dl class="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:grid-cols-4">
            <div><dt class="text-ink-500">رقم الطلب</dt><dd class="num font-semibold">{{ $done['number'] }}</dd></div>
            <div><dt class="text-ink-500">المبلغ</dt><dd class="num font-semibold">{{ number_format($done['amount']) }} د.ع</dd></div>
            <div><dt class="text-ink-500">التاريخ</dt><dd class="num">{{ $done['date'] }}</dd></div>
            <div><dt class="text-ink-500">طريقة الدفع</dt><dd>{{ $done['method'] }}</dd></div>
        </dl>
        <p class="mt-2 text-xs text-ink-500">وصل طلبك الشركة بتفاصيله، وتتابع حالته في «طلباتي الأخيرة» أدناه.</p>
    </section>
@endif

<div class="mb-6 grid grid-cols-1 gap-5 lg:grid-cols-2">
    <form method="POST" action="{{ route('portal.requests.store') }}" class="card space-y-4 p-5">
        @csrf
        <input type="hidden" name="type" value="payment">
        <div class="flex items-start justify-between gap-3">
            <h2 class="text-base font-bold">طلب دفع</h2>
            <div class="text-end">
                <div class="text-xs text-ink-500">{{ $merchant->balance >= 0 ? 'لك الآن' : 'عليك الآن' }}</div>
                <div class="text-xl font-bold {{ $merchant->balance > 0 ? 'text-ok-700' : 'text-bad-700' }}">
                    <span class="num">{{ number_format(abs($merchant->balance)) }}</span> <span class="text-sm font-medium text-ink-500">د.ع</span>
                </div>
            </div>
        </div>
        {{-- طريقة الدفع (docs/plan/44): نقدٌ بيد مندوب الاستلام أو من الشركة، أو بطاقةٌ/محفظةٌ بتفاصيلها --}}
        @php $chosen = old('payout_method', array_key_exists((string) $merchant->payout_method, $offered) ? $merchant->payout_method : array_key_first($offered)); @endphp
        <fieldset class="space-y-2" data-payout>
            <legend class="field-label">كيف تريد استلام مبلغك؟</legend>
            <div class="grid grid-cols-2 gap-2">
                @foreach ($offered as $key => $label)
                    <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-ink-200 px-3 py-2 text-sm has-[:checked]:border-[var(--brand)] has-[:checked]:bg-primary-50">
                        <input type="radio" name="payout_method" value="{{ $key }}" @checked($chosen === $key) class="accent-[var(--brand)]">
                        {{ $label }}
                    </label>
                @endforeach
            </div>
            @error('payout_method') <p class="field-error">{{ $message }}</p> @enderror

            <div class="space-y-1 rounded-xl bg-ink-50 p-3 text-sm" data-payout-cash>
                <label class="flex items-center gap-2">
                    <input type="radio" name="via_pickup_courier" value="1" @checked(old('via_pickup_courier', '1') === '1') class="accent-[var(--brand)]">
                    يوصله لي مندوب الاستلام
                </label>
                <label class="flex items-center gap-2">
                    <input type="radio" name="via_pickup_courier" value="0" @checked(old('via_pickup_courier') === '0') class="accent-[var(--brand)]">
                    أستلمه بنفسي من الشركة
                </label>
            </div>

            <div data-payout-card>
                <label class="field-label" for="payout_details">رقم البطاقة أو المحفظة واسم صاحبها</label>
                <input id="payout_details" name="payout_details" maxlength="255" class="field-input" dir="auto"
                       value="{{ old('payout_details', $merchant->payout_account) }}" placeholder="مثلاً: 07801234567 — علي حسين">
                <p class="mt-1 text-xs text-ink-500">
                    @foreach ($hints as $key => $hint)<span data-payout-hint="{{ $key }}">{{ $hint }}</span>@endforeach
                </p>
                @error('payout_details') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        </fieldset>
        <div>
            <label class="field-label" for="payment_note">ملاحظة (اختياري)</label>
            <textarea id="payment_note" name="note" rows="2" maxlength="500" class="field-input" placeholder="مثال: رقم المحفظة تغيّر"></textarea>
        </div>
        <button type="submit" class="btn-primary" @disabled($merchant->balance <= 0)>أرسل طلب الدفع</button>
        @if ($merchant->balance <= 0)
            <p class="text-xs text-ink-500">لا رصيد لك الآن لتطلب دفعه.</p>
        @endif
    </form>

    <form method="POST" action="{{ route('portal.requests.store') }}" class="card space-y-4 p-5">
        @csrf
        <input type="hidden" name="type" value="returns">
        <div class="flex items-start justify-between gap-3">
            <h2 class="text-base font-bold">طلب كشف راجع</h2>
            <div class="text-end">
                <div class="text-xs text-ink-500">رواجعك عندنا</div>
                <div class="text-xl font-bold">{{ number_format($returning) }}</div>
            </div>
        </div>
        <p class="text-sm text-ink-600">نسلّمك رواجعك بإيصالٍ برقم: تستلمها من المخزن، أو يوصلها مندوب الاستلام.</p>
        <label class="flex items-center gap-2 text-sm">
            <input type="hidden" name="via_pickup_courier" value="0">
            <input type="checkbox" name="via_pickup_courier" value="1" class="size-4 accent-[var(--brand)]" checked>
            أعطوها لمندوب الاستلام يوصلها لي
        </label>
        <div>
            <label class="field-label" for="returns_note">ملاحظة (اختياري)</label>
            <textarea id="returns_note" name="note" rows="2" maxlength="500" class="field-input"></textarea>
        </div>
        <button type="submit" class="btn-primary" @disabled($returning === 0)>أرسل طلب الراجع</button>
    </form>
</div>

<section class="card mb-6 overflow-hidden">
    <h2 class="border-b border-ink-100 px-5 py-4 text-sm font-bold">طلباتي الأخيرة</h2>
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr><th>رقم الطلب</th><th>النوع</th><th>التفاصيل</th><th>الحالة</th><th>التاريخ</th><th></th></tr>
            </thead>
            <tbody>
                @forelse ($requests as $req)
                    <tr>
                        <td class="num font-semibold whitespace-nowrap">{{ $req->number }}</td>
                        <td>{{ $req->typeLabel() }}</td>
                        <td class="text-sm text-ink-600">
                            {{-- الفاصل بين جزأين حاضرين فقط: طلب الراجع لا طريقة دفعٍ له --}}
                            {{ collect([
                                $req->type === 'payment' ? ($methods[$req->payout_method] ?? null) : null,
                                $req->type === 'payment' && $req->amount !== null ? number_format($req->amount).' د.ع' : null,
                                $req->payout_details,
                                $req->via_pickup_courier ? 'مع مندوب الاستلام' : ($req->type === 'payment' && $req->payout_method === 'cash' ? 'من الشركة' : null),
                            ])->filter()->join(' · ') ?: '—' }}
                            @if ($req->note)<div class="text-xs text-ink-500">{{ $req->note }}</div>@endif
                        </td>
                        <td>
                            <span class="chip {{ ['open' => 'chip-warn', 'handled' => 'chip-ok', 'cancelled' => 'chip-mute'][$req->status] ?? 'chip-mute' }}">{{ $req->statusLabel() }}</span>
                            @if ($req->settlement)<div class="num mt-1 text-xs text-ink-500">كشف {{ $req->settlement->code }}</div>@endif
                            @if ($req->returnBatch)<div class="num mt-1 text-xs text-ink-500">إيصال {{ $req->returnBatch->number }}</div>@endif
                        </td>
                        <td class="num text-xs text-ink-500 whitespace-nowrap">{{ $req->created_at->format('Y-m-d H:i') }}</td>
                        <td>
                            @if ($req->isOpen())
                                <form method="POST" action="{{ route('portal.requests.cancel', $req) }}">
                                    @csrf
                                    <button class="btn-ghost py-1 text-xs">ألغِه</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-ink-500">لم ترسل طلباً بعد.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<section class="card overflow-hidden">
    <h2 class="border-b border-ink-100 px-5 py-4 text-sm font-bold">إيصالات الراجع — ما سُلّم لك</h2>
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr><th>رقم الإيصال</th><th>الشحنات</th><th>أجرة الراجع</th><th>راجع عن طريق</th><th>سُلّم</th><th>استلمتُها</th><th></th></tr>
            </thead>
            <tbody>
                @forelse ($batches as $batch)
                    <tr>
                        <td class="num font-semibold">{{ $batch->number }}</td>
                        <td class="num">{{ number_format($batch->shipments_count) }}</td>
                        <td class="num">{{ number_format($batch->return_fees_total) }}</td>
                        <td>{{ $batch->viaLabel() }}</td>
                        <td class="num text-xs text-ink-500 whitespace-nowrap">{{ $batch->handed_at->format('Y-m-d H:i') }}</td>
                        <td class="text-xs">
                            @if ($batch->isReceived())
                                <span class="num">{{ $batch->received_at->format('Y-m-d H:i') }}</span>
                            @else
                                <form method="POST" action="{{ route('portal.requests.returns.confirm', $batch) }}">
                                    @csrf
                                    <button class="btn-primary py-1 text-xs whitespace-nowrap">وصلتني</button>
                                </form>
                            @endif
                        </td>
                        <td><a href="{{ route('portal.requests.returns.print', $batch) }}" target="_blank" class="btn-ghost py-1 text-xs">الإيصال</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-ink-500">لم تُسلَّم لك رواجع بإيصال بعد.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($batches->hasPages())
        <div class="border-t border-ink-100 px-4 py-3">{{ $batches->links() }}</div>
    @endif
</section>
@endsection
