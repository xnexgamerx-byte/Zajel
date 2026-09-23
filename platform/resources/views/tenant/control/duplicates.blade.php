@extends('layouts.app')
@section('title', 'شحنات مشتبه بتكرارها')

@section('content')
<div class="mb-5">
    <h1 class="page-title">شحنات مشتبه بتكرارها</h1>
    <p class="mt-1 text-sm text-ink-500">
        نفس التاجر ونفس هاتف المستلم ونفس المبلغ خلال {{ \App\Support\Arabic::days($window) }}.
        تُحاسَب مرّتين وتُوزَّع مرّتين إن مرّت.
    </p>
</div>

@if ($suspects->isEmpty())
    <section class="card p-10 text-center">
        <p class="text-ink-500">لا اشتباه معلّق.</p>
    </section>
@else
    <div class="space-y-4">
        @foreach ($suspects as $shipment)
            <section class="card border-warn-200 p-5">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    @foreach ([['المشتبه بها', $shipment, 'border-warn-300'], ['الأصل', $shipment->duplicateOf, 'border-ink-200']] as [$label, $row, $border])
                        <div class="rounded-lg border {{ $border }} p-4">
                            <div class="mb-2 text-xs font-semibold text-ink-500">{{ $label }}</div>
                            @if ($row)
                                <a href="{{ route('shipments.show', $row) }}"
                                   class="num text-lg font-bold hover:underline">{{ $row->number }}</a>
                                <dl class="mt-2 space-y-1 text-sm">
                                    <div class="flex justify-between">
                                        <dt class="text-ink-500">التاجر</dt>
                                        <dd>{{ $row->merchant?->business_name }}</dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt class="text-ink-500">المستلم</dt>
                                        <dd>{{ $row->recipient_name }}</dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt class="text-ink-500">الهاتف</dt>
                                        <dd><x-phone :number="$row->recipient_phone" :name="$row->recipient_name" /></dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt class="text-ink-500">المبلغ</dt>
                                        <dd class="num font-semibold">{{ number_format($row->cod_amount) }}</dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt class="text-ink-500">أُنشئت</dt>
                                        <dd class="num">{{ $row->created_at->format('Y-m-d H:i') }}</dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt class="text-ink-500">الحالة</dt>
                                        <dd><x-status-badge :status="$row->status" /></dd>
                                    </div>
                                </dl>
                            @else
                                <p class="text-sm text-ink-400">حُذف الأصل.</p>
                            @endif
                        </div>
                    @endforeach
                </div>

                <div class="mt-4 flex flex-wrap gap-3">
                    <form method="POST" action="{{ route('control.duplicates.cancel', $shipment) }}">
                        @csrf
                        <button type="submit" class="btn-danger">تكرار فعليّ — ألغِ المشتبه بها</button>
                    </form>
                    <form method="POST" action="{{ route('control.duplicates.clear', $shipment) }}">
                        @csrf
                        <button type="submit" class="btn-ghost">طلبان مختلفان — ارفع الاشتباه</button>
                    </form>
                    <p class="ms-auto self-center text-xs text-ink-500">الإلغاء لا يحذف: يبقى الوصل وسجلّه.</p>
                </div>
            </section>
        @endforeach
    </div>
@endif
@endsection
