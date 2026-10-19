@extends('layouts.print', ['back' => route('profits.withdrawals.index')])
@section('title', 'سند صرف '.$w->number)
@section('docTitle', 'سند صرف — سحب أرباح')
@section('subtitle', $w->number.' · '.$w->withdrawn_on->format('Y-m-d'))

@section('content')
<section class="text-sm">
    @unless ($w->isPaid())
        <p class="mb-4 rounded-lg border border-bad-200 bg-bad-50 p-3 font-semibold text-bad-700">هذا السحب {{ $w->statusLabel() }}: السند للاطّلاع لا للصرف.</p>
    @endunless

    <div class="mb-6 grid grid-cols-2 gap-x-8 gap-y-2">
        @foreach ([
            ['رقم السند', $w->number],
            ['التاريخ', $w->withdrawn_on->format('Y-m-d')],
            ['المبلغ', number_format($w->amount).' د.ع'],
            ['المستفيد', $w->withdrawn_by ?? '—'],
            ['من', ($w->cashBox?->name ?? '—').($w->cashBox?->type === 'bank' ? ' ('.$w->cashBox->typeLabel().')' : '')],
            ['السبب', $w->note ?? '—'],
            ['طلبه', $w->createdBy?->name ?? '—'],
            ['اعتمده', $w->approvedBy?->name ?? '—'],
            ['دفعه', ($w->paidBy?->name ?? '—').($w->paid_at ? ' — '.$w->paid_at->format('Y-m-d H:i') : '')],
            ['المتاح قبل الصرف وبعده', $w->available_before === null ? '—' : number_format($w->available_before).' ← '.number_format($w->available_after)],
        ] as [$label, $value])
            <div class="flex justify-between gap-3 border-b border-ink-200 py-1.5"><span class="text-ink-600">{{ $label }}</span><span class="num font-semibold">{{ $value }}</span></div>
        @endforeach
    </div>

    <p class="mb-10 text-xs text-ink-600">سحبٌ من أرباح الشركة (حقوق ملكية)، لا مصروف تشغيلي.</p>

    <div class="grid grid-cols-3 gap-6 text-center text-xs">
        @foreach (['المستلم', 'المحاسب', 'المدير'] as $role)
            <div><div class="mb-12 font-semibold">{{ $role }}</div><div class="border-t border-ink-400 pt-1 text-ink-500">التوقيع</div></div>
        @endforeach
    </div>
</section>
@endsection
