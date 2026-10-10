@props(['merchant', 'title' => null, 'staff' => false])

@php
    /*
    | حساب التاجر كما في الصورة التي أرسلتها (docs/plan/49): «إجمالي المستحقات» و«المتاح للسحب»
    | جنباً إلى جنب، وتحتهما «قيد المطابقة» بسببه — واصلٌ نقده ما زال مع المندوب — وما في
    | الإجمالي غيره: كشفٌ مُقفَل ينتظر الدفع، وسلفةٌ تُقتطع (docs/plan/51). فيُقرأ الإجمالي كلّه.
    */
    $b = \App\Support\MerchantBalance::of($merchant);
@endphp

<section {{ $attributes->merge(['class' => 'card p-4 sm:p-5']) }} data-merchant-balance>
    @if ($title)
        <h2 class="mb-3 text-base font-bold">{{ $title }}</h2>
    @endif

    <div class="grid grid-cols-2 gap-3">
        <div class="rounded-2xl bg-ink-50 p-3.5 sm:p-4">
            <div class="text-[13px] font-semibold text-ink-600 sm:text-sm">{{ $b->owed() ? 'إجمالي المستحقات عن الواصل' : 'عليك للشركة' }}</div>
            <div class="mt-1 text-[clamp(1.4rem,6vw,2rem)] font-bold leading-tight num {{ $b->owed() ? 'text-ink-900' : 'text-bad-700' }}" dir="ltr">
                {{ number_format(abs($b->total)) }}
            </div>
            <div class="text-xs text-ink-500">د.ع</div>
        </div>
        <div class="rounded-2xl bg-ink-50 p-3.5 sm:p-4">
            <div class="whitespace-nowrap text-[13px] font-semibold text-ink-600 sm:text-sm">المتاح للسحب</div>
            <div class="mt-1 text-[clamp(1.4rem,6vw,2rem)] font-bold leading-tight num text-ok-700" dir="ltr" data-available>
                {{ number_format($b->available()) }}
            </div>
            <div class="text-xs text-ink-500">د.ع</div>
        </div>
    </div>

    @if ($b->pending > 0)
        <div class="mt-4 flex items-start gap-3">
            <x-icon name="clock" class="mt-0.5 size-6 shrink-0 text-warn-700" />
            <div class="min-w-0">
                <div class="font-bold"><span class="num" dir="ltr">{{ number_format($b->pending) }}</span> د.ع قيد المطابقة</div>
                <p class="mt-0.5 text-sm text-ink-500">{{ $b->reason() }}</p>
            </div>
        </div>
    @endif

    @if ($b->confirmed > 0)
        <div class="mt-3 flex items-start gap-3" data-awaiting-payment>
            <x-icon name="check" class="mt-0.5 size-6 shrink-0 text-ok-700" />
            <div class="min-w-0">
                <div class="font-bold"><span class="num" dir="ltr">{{ number_format($b->confirmed) }}</span> د.ع بانتظار الدفع</div>
                <p class="mt-0.5 text-sm text-ink-500">
                    {{ $b->confirmedCount > 1 ? $b->confirmedCount.' كشوف أُقفلت' : 'كشف '.$b->confirmedFirst?->code.' أُقفل' }}
                    ولم يُسجَّل دفعه بعد — من «المتاح للسحب».
                </p>
            </div>
        </div>
    @endif

    @if ($b->advances > 0)
        <div class="mt-3 flex items-start gap-3" data-advances>
            <x-icon name="cash" class="mt-0.5 size-6 shrink-0 text-bad-700" />
            <div class="min-w-0">
                <div class="font-bold"><span class="num" dir="ltr">{{ number_format($b->advances) }}</span> د.ع سلفة {{ $staff ? 'عليه' : 'عليك' }}</div>
                <p class="mt-0.5 text-sm text-ink-500">تُقتطع من الكشف القادم، والمتاح للسحب بعد اقتطاعها.</p>
            </div>
        </div>
    @endif

    @if ($staff && $b->unexplained() !== 0)
        {{-- رصيدٌ لا يقابله كشفٌ ولا مطابقة: أثرُ قيدٍ يُراجَع، لا يُدفع ولا يُخفى (docs/plan/51) --}}
        <div class="mt-3 rounded-xl bg-bad-50 p-3 text-sm text-bad-700" data-unexplained>
            فرق <span class="num font-bold" dir="ltr">{{ number_format($b->unexplained()) }}</span> د.ع في رصيده لا يقابله كشفٌ ولا شحنة —
            راجعه في «مطابقة الدفتر» قبل الدفع.
        </div>
    @endif

    @if ($b->pending <= 0 && $b->confirmed <= 0 && $b->advances <= 0 && $b->available() > 0)
        <p class="mt-4 flex items-center gap-2 text-sm text-ok-700">
            <x-icon name="check" class="size-5" /> كلّ مستحقّك متاحٌ للسحب.
        </p>
    @endif

    @if (trim((string) $slot) !== '')
        <div class="mt-4 flex flex-wrap gap-2 border-t border-ink-100 pt-4">{{ $slot }}</div>
    @endif
</section>
