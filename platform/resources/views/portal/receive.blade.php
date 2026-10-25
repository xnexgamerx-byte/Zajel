@extends('layouts.portal')
@section('title', 'استلام بالباركود')

@section('content')
{{--
  «استلام بالباركود» (docs/plan/69): وصل الحساب يأتي مع المبلغ، وإيصال الراجع مع الرواجع — وعلى كلٍّ
  باركودٌ يُمسح فيُؤكَّد استلام ما عليه. قارئ الباركود يكتب في الحقل ويضغط Enter، وكاميرا الهاتف تفتح
  رابط الورقة فيصل إلى هنا وفيه الرمز، وإن تعذّر المسح يُكتب الرمز المطبوع تحت الباركود.
--}}
<div class="mb-5">
    <h1 class="page-title">استلام بالباركود</h1>
    <p class="mt-1 text-sm text-ink-500">امسح الورقة التي وصلتك مع حسابك أو مع رواجعك، فيُؤكَّد استلامها.</p>
</div>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <div class="lg:col-span-2">
        @if ($doc)
            @php($isStatement = $doc instanceof \App\Models\MerchantSettlement)
            <section class="card mb-5 border-2 border-[var(--brand)] p-5" aria-labelledby="scanned-doc">
                <p class="text-xs font-semibold text-[var(--brand)]">الورقة التي مسحتها</p>
                <h2 id="scanned-doc" class="mt-1 text-lg font-bold">
                    {{ $isStatement ? 'وصل الكشف' : 'إيصال الراجع' }}
                    <span class="num" dir="ltr">{{ $isStatement ? $doc->code : $doc->number }}</span>
                </h2>
                <p class="mt-1 text-sm text-ink-600">
                    @if ($isStatement)
                        <span class="num font-bold">{{ number_format($doc->net_amount) }}</span> د.ع
                        · {{ \App\Support\Arabic::shipments($doc->shipments_count) }}
                        @if ($doc->paid_at) · دُفع {{ $doc->paid_at->format('Y-m-d H:i') }} @endif
                    @else
                        راجعة: {{ \App\Support\Arabic::shipments($doc->shipments_count) }} · {{ $doc->viaLabel(true) }}
                        · سُلّمت {{ $doc->handed_at?->format('Y-m-d H:i') }}
                    @endif
                </p>

                @if ($isStatement ? $doc->merchant_confirmed_at : $doc->received_at)
                    <p class="mt-3 text-sm font-semibold text-ok-700">أكّدتَ استلامها سلفاً.</p>
                @elseif ($isStatement && $doc->status !== 'paid')
                    <p class="mt-3 text-sm font-semibold text-warn-700">هذا الكشف لم يُدفع بعد — يُؤكَّد حين يصلك مبلغه.</p>
                @else
                    <form method="POST" action="{{ route('portal.receive.store') }}" class="mt-4">
                        @csrf
                        <input type="hidden" name="code" value="{{ $scanned }}">
                        <button class="btn-primary">أكّد أنها وصلتني</button>
                    </form>
                @endif
            </section>
        @elseif (filled($scanned))
            <div class="card mb-5 border border-bad-200 bg-bad-50 p-4 text-sm text-bad-700" role="alert">
                {{ \App\Actions\Receipts\ConfirmReceipt::NOT_YOURS }}
            </div>
        @endif

        <form method="POST" action="{{ route('portal.receive.store') }}" class="card p-5">
            @csrf
            <label class="field-label" for="receipt-code">باركود الورقة أو رمزها</label>
            <div class="flex gap-2">
                <input id="receipt-code" name="code" class="field-input text-lg" autocomplete="off" autofocus dir="ltr"
                       value="{{ old('code') }}" placeholder="امسح الباركود، أو اكتب الرمز مثل ABCDE-FGH23"
                       aria-describedby="receipt-code-hint">
                <button class="btn-primary whitespace-nowrap">أكّد الاستلام</button>
            </div>
            @error('code')
                <p class="mt-2 text-sm text-bad-700" role="alert">{{ $message }}</p>
            @enderror
            <ul id="receipt-code-hint" class="mt-3 space-y-1 text-sm text-ink-500">
                <li>بقارئ الباركود: امسح الرمز على الورقة فيُكتب هنا.</li>
                <li>بالهاتف: وجّه الكاميرا على الرمز واضغط الرابط الذي يظهر، فتفتح هذه الصفحة وفيها الورقة.</li>
                <li>إن تعذّر المسح: اكتب الرمز المطبوع تحت الباركود — عشرة أحرف.</li>
            </ul>
        </form>
    </div>

    <section class="card p-5" aria-labelledby="awaiting">
        <h2 id="awaiting" class="mb-1 text-sm font-bold">بانتظار تأكيدك</h2>
        <p class="mb-4 text-xs text-ink-500">تُؤكَّد بمسح ورقتها حين تصلك.</p>

        @if ($settlements->isEmpty() && $batches->isEmpty())
            <p class="py-6 text-center text-sm text-ink-500">لا شيء ينتظر تأكيدك.</p>
        @else
            <ul class="divide-y divide-ink-100 text-sm">
                @foreach ($settlements as $settlement)
                    <li class="flex items-center justify-between gap-2 py-2.5">
                        <span>
                            <span class="block font-semibold">وصل الكشف <span class="num" dir="ltr">{{ $settlement->code }}</span></span>
                            <span class="text-xs text-ink-500">دُفع {{ $settlement->paid_at?->format('Y-m-d') }}</span>
                        </span>
                        <span class="num font-bold">{{ number_format($settlement->net_amount) }}</span>
                    </li>
                @endforeach
                @foreach ($batches as $batch)
                    <li class="flex items-center justify-between gap-2 py-2.5">
                        <span>
                            <span class="block font-semibold">إيصال الراجع <span class="num">{{ $batch->number }}</span></span>
                            <span class="text-xs text-ink-500">{{ $batch->viaLabel(true) }} · {{ $batch->handed_at?->format('Y-m-d') }}</span>
                        </span>
                        <span class="text-xs text-ink-600">{{ \App\Support\Arabic::shipments($batch->shipments_count) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
@endsection
