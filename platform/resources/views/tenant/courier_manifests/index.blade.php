@extends('layouts.app')
@section('title', 'كشوف المناديب')

@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="text-xl font-bold">كشوف مناديب التوصيل</h1>
        <p class="mt-1 text-sm text-ink-500">
            ما بيد كل مندوب الآن — ورقةٌ تُطبَع ويوقّع عليها عند الخروج، ويُطابَق بها عند العودة.
        </p>
    </div>
</div>

<div class="mb-5 grid grid-cols-3 gap-4">
    <div class="stat">
        <span class="stat-label">مناديب في الطريق</span>
        <span class="stat-value num">{{ number_format($totals->couriers) }}</span>
    </div>
    <div class="stat">
        <span class="stat-label">شحنات بأيديهم</span>
        <span class="stat-value num">{{ number_format($totals->shipments) }}</span>
    </div>
    <div class="stat">
        <span class="stat-label">مبالغ يُتوقَّع تحصيلها</span>
        <span class="stat-value num text-warn-700">{{ number_format($totals->cod) }}</span>
    </div>
</div>

@if ($couriers->isEmpty())
    <section class="card p-10 text-center">
        <p class="font-medium">لا مندوب في الطريق الآن.</p>
        <p class="mt-1 text-sm text-ink-500">تظهر هنا كشوف المناديب الذين أُسنِدت إليهم شحنات ولم يُقفلوها بعد.</p>
    </section>
@else
    <section class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>المندوب</th>
                        <th>الهاتف</th>
                        <th>بيده</th>
                        <th>مبالغها</th>
                        <th>أقدمها</th>
                        <th>نقدٌ سابق بيده</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($couriers as $courier)
                        @php
                            $row = $held->get($courier->id);
                            $oldest = $row?->oldest ? \Illuminate\Support\Carbon::parse($row->oldest) : null;
                            $days = $oldest ? (int) $oldest->diffInDays(now()) : 0;
                        @endphp
                        <tr>
                            <td>
                                <span class="font-medium">{{ $courier->name }}</span>
                                {{-- مَن لا يُفترض أن تكون بيده عهدة: يُسمّى لا يُخفى --}}
                                @if ($courier->deleted_at)
                                    <span class="chip chip-bad mt-0.5 block w-fit">محذوف وبيده عهدة</span>
                                @elseif ($courier->status !== 'active')
                                    <span class="chip chip-bad mt-0.5 block w-fit">موقوف وبيده عهدة</span>
                                @elseif ($courier->type === 'pickup')
                                    <span class="chip chip-warn mt-0.5 block w-fit">مندوب استلام</span>
                                @endif
                            </td>
                            <td class="num text-ink-600">{{ $courier->phone }}</td>
                            <td class="num font-semibold">{{ number_format($row->shipments) }}</td>
                            <td class="num">{{ number_format($row->cod) }}</td>
                            <td>
                                {{-- شحنةٌ عند المندوب منذ ثلاثة أيام ليست «في الطريق» --}}
                                <span class="chip {{ $days >= 3 ? 'chip-bad' : ($days >= 1 ? 'chip-warn' : 'chip-mute') }}">
                                    {{ $days === 0 ? 'اليوم' : \App\Support\Arabic::days($days) }}
                                </span>
                            </td>
                            <td class="num text-ink-600">{{ number_format($courier->cash_in_hand) }}</td>
                            <td class="text-end">
                                <a href="{{ route('courier-manifests.show', $courier) }}" class="btn-ghost">افتح الكشف</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif
@endsection
