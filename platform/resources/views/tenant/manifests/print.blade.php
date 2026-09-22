@extends('layouts.print', ['back' => route('manifests.show', $manifest)])
@section('title', 'كشف النقل '.$manifest->code)
@section('docTitle', 'كشف نقل '.$manifest->code)
@section('subtitle', ($manifest->fromHub?->name ?? '—').' ← '.($manifest->toHub?->name ?? '—'))

@section('content')
<div class="mb-5 grid grid-cols-2 gap-x-8 gap-y-2 text-sm">
    <div class="flex justify-between border-b border-ink-200 py-1"><span class="text-ink-600">السائق</span><span>{{ $manifest->driver_name ?? '—' }}</span></div>
    <div class="flex justify-between border-b border-ink-200 py-1"><span class="text-ink-600">هاتفه</span><span class="num">{{ $manifest->driver_phone ?? '—' }}</span></div>
    <div class="flex justify-between border-b border-ink-200 py-1"><span class="text-ink-600">المركبة</span><span>{{ $manifest->vehicle_number ?? '—' }}</span></div>
    <div class="flex justify-between border-b border-ink-200 py-1"><span class="text-ink-600">الحالة</span><span>{{ $manifest->statusLabel() }}</span></div>
    <div class="flex justify-between border-b border-ink-200 py-1"><span class="text-ink-600">خرج</span><span class="num">{{ $manifest->departed_at?->format('Y-m-d H:i') ?? '—' }}</span></div>
    <div class="flex justify-between border-b border-ink-200 py-1"><span class="text-ink-600">وصل</span><span class="num">{{ $manifest->arrived_at?->format('Y-m-d H:i') ?? '—' }}</span></div>
</div>

<table class="tbl w-full">
    <thead>
        <tr>
            <th class="w-8">#</th>
            <th>الكيس</th>
            <th>عدد الشحنات</th>
            <th>خُتم</th>
            <th>الوصول</th>
            {{-- يُعلَّم باليد عند الاستلام، قبل إدخاله في النظام --}}
            <th class="w-24">استُلم ✓</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($manifest->bags as $i => $bag)
            <tr>
                <td class="num text-ink-500">{{ $i + 1 }}</td>
                <td class="num font-semibold">{{ $bag->code }}</td>
                <td class="num">{{ number_format($bag->shipments_count) }}</td>
                <td class="num text-xs">{{ $bag->sealed_at?->format('Y-m-d H:i') ?? '—' }}</td>
                <td>
                    @if ($bag->pivot->is_missing)
                        <span class="chip chip-bad">لم يصل</span>
                    @elseif ($manifest->status === 'arrived')
                        <span class="chip chip-ok">وصل</span>
                    @endif
                </td>
                <td></td>
            </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr class="border-t-2 border-ink-900">
            <td colspan="2" class="text-end font-bold">المجموع</td>
            <td class="num font-black">{{ number_format($manifest->bags->sum('shipments_count')) }}</td>
            <td colspan="3" class="text-sm">{{ \App\Support\Arabic::bags($manifest->bags->count()) }}</td>
        </tr>
    </tfoot>
</table>
@endsection

@section('signatures')
    {{-- ثلاثة: مَن حمّل، ومَن قاد، ومَن استلم — وبغياب أحدهم لا يُعرف أين ضاع الكيس --}}
    <div class="flex-1">
        <div>أرسله: {{ $dispatcher?->name ?? '..................' }}</div>
        <div class="mt-8 border-t border-ink-400 pt-1">التوقيع</div>
    </div>
    <div class="flex-1">
        <div>السائق: {{ $manifest->driver_name ?? '..................' }}</div>
        <div class="mt-8 border-t border-ink-400 pt-1">التوقيع</div>
    </div>
    <div class="flex-1">
        <div>استلمه: {{ $receiver?->name ?? '..................' }}</div>
        <div class="mt-8 border-t border-ink-400 pt-1">التوقيع</div>
    </div>
@endsection
