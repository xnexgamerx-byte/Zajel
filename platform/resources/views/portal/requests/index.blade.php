@extends('layouts.portal')
@section('title', 'طلباتي')

@section('content')
<div class="mb-5">
    <h1 class="page-title">طلباتي</h1>
    <p class="mt-1 text-sm text-ink-500">اطلب مستحقّاتك أو رواجعك من هنا بلا اتصال — برقمٍ تتابع حالته في هذه الصفحة.</p>
</div>

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
        <div>
            <label class="field-label" for="payout_method">طريقة الدفع المطلوبة</label>
            <select id="payout_method" name="payout_method" class="field-input">
                @foreach ($methods as $key => $label)
                    <option value="{{ $key }}" @selected(old('payout_method', $merchant->payout_method) === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <label class="flex items-center gap-2 text-sm">
            <input type="hidden" name="via_pickup_courier" value="0">
            <input type="checkbox" name="via_pickup_courier" value="1" class="size-4 accent-[var(--brand)]">
            أعطوا المبلغ لمندوب الاستلام يوصله لي
        </label>
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
                            {{ $req->type === 'payment' ? ($methods[$req->payout_method] ?? '—') : '' }}
                            @if ($req->via_pickup_courier) · مع مندوب الاستلام @endif
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
    <h2 class="border-b border-ink-100 px-5 py-4 text-sm font-bold">دفعات الراجع — ما سُلّم لك بإيصال</h2>
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
