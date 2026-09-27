@extends('layouts.print', ['back' => $back ?? route('return-batches.index')])
@section('title', $batches->count() === 1 ? 'إيصال راجع '.$batches->first()->number : 'إيصالات راجع')
@section('docTitle', 'إيصال تسليم راجع')
@section('subtitle', $batches->count() === 1 ? $batches->first()->merchant?->business_name : 'لكلّ تاجرٍ صفحته')

@section('content')
@foreach ($batches as $batch)
    {{-- لكلّ تاجرٍ صفحة: تُقطع وتُعطى له عند بابه --}}
    <section class="@unless ($loop->last) mb-10 break-after-page @endunless">
        <div class="mb-4 flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-lg font-bold">{{ $batch->merchant?->business_name }}
                <span class="num text-sm font-normal text-ink-600">{{ $batch->merchant?->code }}</span></h2>
            <div class="num text-base font-bold">{{ $batch->number }}</div>
        </div>

        <div class="mb-5 grid grid-cols-2 gap-x-8 gap-y-2 text-sm">
            <div class="flex justify-between border-b border-ink-200 py-1"><span class="text-ink-600">هاتف التاجر</span><span class="num">{{ $batch->merchant?->phone }}</span></div>
            <div class="flex justify-between border-b border-ink-200 py-1"><span class="text-ink-600">العنوان</span><span>{{ collect([$batch->merchant?->city?->name_ar, $batch->merchant?->address])->filter()->implode(' — ') ?: '—' }}</span></div>
            <div class="flex justify-between border-b border-ink-200 py-1"><span class="text-ink-600">التسليم</span><span>{{ $batch->viaLabel() }}</span></div>
            <div class="flex justify-between border-b border-ink-200 py-1"><span class="text-ink-600">وقته</span><span class="num">{{ $batch->handed_at->format('Y-m-d H:i') }}</span></div>
        </div>

        <table class="tbl w-full">
            <thead>
                <tr>
                    <th class="w-8">#</th>
                    <th>رقم الوصل</th>
                    <th>المستلم</th>
                    <th>الوجهة</th>
                    <th>سبب الرجوع</th>
                    <th>أجرة الراجع</th>
                    {{-- يُعلَّم باليد عند الاستلام --}}
                    <th class="w-20">استُلم ✓</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($batch->shipments as $i => $shipment)
                    <tr>
                        <td class="num text-ink-500">{{ $i + 1 }}</td>
                        <td class="num font-semibold">{{ $shipment->number }}</td>
                        <td>{{ $shipment->recipient_name }}</td>
                        <td>{{ $shipment->governorate?->name_ar }}</td>
                        <td>{{ $shipment->lastFailureReason?->name_ar ?? '—' }}</td>
                        <td class="num">{{ number_format($shipment->return_fee) }}</td>
                        <td></td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-ink-900">
                    <td colspan="2" class="font-bold">{{ \App\Support\Arabic::shipments($batch->shipments_count) }}</td>
                    <td colspan="3" class="text-end font-bold">مجموع أجرة الراجع</td>
                    <td class="num font-black">{{ number_format($batch->return_fees_total) }}</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>

        @if ($batch->note)
            <p class="mt-3 text-sm text-ink-600">ملاحظة: {{ $batch->note }}</p>
        @endif

        {{-- مَن سلّم، ومَن حمل، ومَن استلم — وبغياب أحدهم لا يُعرف أين ضاع الطرد --}}
        <div class="mt-8 flex items-end justify-between gap-8 text-xs text-ink-600">
            <div class="flex-1">
                <div>سلّمه: {{ $batch->handedBy?->name ?? '..................' }}</div>
                <div class="mt-8 border-t border-ink-400 pt-1">التوقيع</div>
            </div>
            @if ($batch->courier)
                <div class="flex-1">
                    <div>حمله: {{ $batch->courier->name }}</div>
                    <div class="mt-8 border-t border-ink-400 pt-1">التوقيع</div>
                </div>
            @endif
            <div class="flex-1">
                <div>استلمه: ..................</div>
                <div class="mt-8 border-t border-ink-400 pt-1">توقيع التاجر وتاريخ الاستلام</div>
            </div>
        </div>
    </section>
@endforeach
@endsection
