@extends('layouts.print', ['back' => route('branch-accounts.remittances')])
@section('title', 'وصل حوالة '.$remittance->number)
@section('docTitle', 'وصل مبلغ مُرسَل بين الفروع')
@section('subtitle', ($remittance->fromBranch?->name ?? '—').' ← '.($remittance->toBranch?->name ?? '—'))

@section('content')
{{-- يرافق النقد: الفرع المستلم يمسح الرمز فتُفتح الحوالة ليستلمها بمبلغها الفعليّ (docs/plan/61) --}}
<div class="mb-6 flex items-center justify-between gap-6">
    <dl class="grid flex-1 grid-cols-2 gap-3 text-sm">
        <div class="rounded-lg border border-ink-300 p-3"><dt class="text-xs text-ink-600">رقم الحوالة</dt><dd class="num text-lg font-bold">{{ $remittance->number }}</dd></div>
        <div class="rounded-lg border-2 border-ink-900 p-3"><dt class="text-xs text-ink-600">المبلغ (د.ع)</dt><dd class="num text-xl font-black">{{ number_format($remittance->amount) }}</dd></div>
        <div class="rounded-lg border border-ink-300 p-3"><dt class="text-xs text-ink-600">أرسله</dt><dd class="font-semibold">{{ $remittance->sentBy?->name ?? '—' }}</dd></div>
        <div class="rounded-lg border border-ink-300 p-3"><dt class="text-xs text-ink-600">متى</dt><dd class="num font-semibold">{{ $remittance->sent_at?->format('Y-m-d H:i') }}</dd></div>
    </dl>
    <figure class="shrink-0 text-center">
        <img src="{{ \App\Support\QrCode::dataUri(route('doc.remittance', $remittance)) }}" alt="" class="size-36">
        <figcaption class="mt-1 text-[11px] text-ink-600">امسحه عند الاستلام</figcaption>
    </figure>
</div>
@endsection

@section('signatures')
    <div class="flex-1 border-t border-ink-400 pt-2 text-center">توقيع المُرسِل</div>
    <div class="flex-1 border-t border-ink-400 pt-2 text-center">توقيع المستلم</div>
@endsection
