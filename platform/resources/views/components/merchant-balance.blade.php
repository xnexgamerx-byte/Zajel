@props(['merchant', 'title' => null])

@php
    /*
    | حساب التاجر كما في الصورة التي أرسلتها (docs/plan/49): «إجمالي المستحقات» و«المتاح للسحب»
    | جنباً إلى جنب، وتحتهما «قيد المطابقة» بسببه — واصلٌ نقده ما زال مع المندوب.
    */
    $b = \App\Support\MerchantBalance::of($merchant);
@endphp

<section {{ $attributes->merge(['class' => 'card p-4 sm:p-5']) }} data-merchant-balance>
    @if ($title)
        <h2 class="mb-3 text-base font-bold">{{ $title }}</h2>
    @endif

    <div class="grid grid-cols-2 gap-3">
        <div class="rounded-2xl bg-ink-50 p-3.5 sm:p-4">
            <div class="whitespace-nowrap text-[13px] font-semibold text-ink-600 sm:text-sm">{{ $b->owed() ? 'إجمالي المستحقات' : 'عليك للشركة' }}</div>
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
    @elseif ($b->available() > 0)
        <p class="mt-4 flex items-center gap-2 text-sm text-ok-700">
            <x-icon name="check" class="size-5" /> كلّ مستحقّك متاحٌ للسحب.
        </p>
    @endif

    @if (trim((string) $slot) !== '')
        <div class="mt-4 flex flex-wrap gap-2 border-t border-ink-100 pt-4">{{ $slot }}</div>
    @endif
</section>
