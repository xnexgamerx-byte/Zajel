@extends('layouts.app')
@section('title', 'استلام الكشف '.$manifest->code)

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="page-title">استلام الكشف <span class="num" dir="ltr">{{ $manifest->code }}</span></h1>
        <p class="mt-1 text-sm text-ink-500">
            من {{ $manifest->fromHub?->name }} إلى {{ $manifest->toHub?->name }}
            @if ($manifest->courier) · يحمله {{ $manifest->courier->name }} @endif
            — امسح كلّ طلبٍ بوحده عند فتح الأكياس. ما لا يُمسح يُسجَّل «لم يصل» باسمه.
        </p>
    </div>
    <a href="{{ route('transfers.index') }}" class="btn-ghost">رجوع</a>
</div>

<x-scan-box :lookup="route('transfers.lookup', $manifest)" hint="امسح الطلبات واحداً واحداً — كلّ ممسوحٍ يُعلَّم ويصعد أعلى القائمة." />

<form method="POST" action="{{ route('transfers.receive', $manifest) }}" class="card overflow-hidden">
    @csrf
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-ink-100 px-5 py-3">
        <h2 class="card-title">طلبات الكشف <span class="num ms-1 text-ink-500">({{ number_format($shipments->count()) }})</span></h2>
        <button type="submit" class="btn-primary" @disabled($shipments->isEmpty())>استلم الممسوح</button>
    </div>
    @if ($shipments->isEmpty())
        <p class="px-5 py-8 text-center text-sm text-ink-500">لا طلبات في أكياس هذا الكشف — فُتحت كلّها سلفاً.</p>
    @else
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr><th class="w-10"></th><th>رقم الوصل</th><th>صاحب المحل</th><th>الوجهة</th><th>المرحلة</th></tr>
                </thead>
                <tbody>
                    @foreach ($shipments as $shipment)
                        <tr>
                            <td><input type="checkbox" name="shipment_ids[]" value="{{ $shipment->id }}" class="accent-[var(--brand)]"
                                       aria-label="وصل {{ $shipment->number }}"></td>
                            <td class="num font-semibold" dir="ltr">{{ $shipment->number }}</td>
                            <td>{{ $shipment->merchant?->business_name }}</td>
                            <td class="text-ink-600">{{ $shipment->governorate?->name_ar }}{{ $shipment->city ? ' · '.$shipment->city->name_ar : '' }}</td>
                            <td><x-status-badge :status="$shipment->status" :shipment="$shipment" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="border-t border-ink-100 px-5 py-3 text-xs text-ink-500">
            ما لم يُعلَّم حين تضغط «استلم الممسوح» يُسجَّل «لم يصل» ويظهر في «طلبات لم تصل»؛ وإن وُجد بعدها فامسحه في «استلام بالمسح».
        </p>
    @endif
</form>
@endsection
