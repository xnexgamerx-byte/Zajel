@extends('layouts.app')
@section('title', 'النقل بين الفروع')

@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="page-title">النقل بين الفروع</h1>
        <p class="mt-1 text-sm text-ink-500">
            تستلم الواصل إليك بضغطة، وترسل إلى فرعٍ بضغطة: الكيس والكشف يُبنيان وحدهما. والراجع يسافر راجعاً ويصل جاهزاً لتسليم تاجره.
        </p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        @if ($canChooseHub && $hubs->count() > 1)
            <form method="GET" action="{{ route('transfers.index') }}" class="flex items-center gap-2">
                <label class="text-sm text-ink-500" for="from">مخزن</label>
                <select id="from" name="from" class="field-input py-1.5" data-submit-on-change>
                    @foreach ($hubs as $hub)
                        <option value="{{ $hub->id }}" @selected($here?->id === $hub->id)>{{ $hub->name }}</option>
                    @endforeach
                </select>
            </form>
        @endif
        <a href="{{ route('manifests.archive') }}" class="btn-ghost">أرشيف الكشوف</a>
    </div>
</div>

@if (! $here)
    <section class="card p-10 text-center">
        <p class="font-medium">لا مخزن مفعّل لفرعك.</p>
        <p class="mt-1 text-sm text-ink-500">أضِف للفرع مركزاً من «الفروع» ليُرسَل منه ويُستلَم فيه.</p>
    </section>
@else
    {{-- ١) الواصل إليك --}}
    <section class="card mb-5 overflow-hidden">
        <div class="border-b border-ink-200 px-5 py-4">
            <h2 class="card-title">واصل إليك
                <span class="num ms-1 text-ink-500">({{ number_format($incoming->count()) }})</span></h2>
            <p class="card-hint">
                «استلمت الكل» يُدخل الشحنات مخزنك، والراجع يصل راجعاً فيظهر في «تسليم الراجع للتاجر».
                وإن وصل ناقصاً فحدّد الأكياس الواصلة من «وصل ناقص؟».
            </p>
        </div>
        @if ($incoming->isEmpty())
            <p class="px-5 py-6 text-center text-sm text-ink-500">لا شيء في الطريق إليك الآن.</p>
        @else
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead>
                        <tr><th>الكشف</th><th>من</th><th>يحمله</th><th>شحنات</th><th>غادر</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($incoming as $manifest)
                            <tr>
                                <td class="whitespace-nowrap"><a href="{{ route('manifests.show', $manifest) }}" class="num font-semibold hover:underline">{{ $manifest->code }}</a></td>
                                <td class="text-ink-600">{{ $manifest->fromHub?->name }}</td>
                                <td class="text-ink-600">{{ $manifest->courier?->name ?? $manifest->driver_name ?? '—' }}</td>
                                <td class="num font-semibold">{{ number_format($manifest->shipments_count) }}</td>
                                <td class="num whitespace-nowrap text-ink-500">{{ $manifest->departed_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                <td class="whitespace-nowrap text-end">
                                    <form method="POST" action="{{ route('transfers.receive', $manifest) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="btn-primary">استلمت الكل</button>
                                    </form>
                                    @if ($manifest->status === 'dispatched')
                                        <a href="{{ route('manifests.inbound') }}" class="btn-ghost">وصل ناقص؟</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- ٢) أرسل إلى فرع --}}
    <section class="card mb-5 overflow-hidden">
        <div class="border-b border-ink-200 px-5 py-4">
            <h2 class="card-title">أرسل إلى فرع</h2>
            <p class="card-hint">اختر الفرع: تظهر شحنات محافظته التي على رفّك، ورواجع تجّاره المستلَمة من المناديب.</p>
        </div>

        @if ($destinations->isEmpty())
            <p class="px-5 py-6 text-center text-sm text-ink-500">لا فرع آخر له مخزن مفعّل.</p>
        @else
            <div class="flex flex-wrap gap-2 px-5 py-4">
                @php $shared = $destinations->countBy('branch_id'); @endphp
                @foreach ($destinations as $hub)
                    @php $count = $counts->get($hub->id, ['shipments' => 0, 'returns' => 0]); @endphp
                    <a href="{{ route('transfers.index', array_filter(['from' => $canChooseHub ? $here->id : null, 'to' => $hub->id])) }}"
                       class="chip {{ $to?->id === $hub->id ? 'chip-info' : 'chip-mute' }}">
                        {{ $hub->branch?->name ?? $hub->name }}
                        {{-- فرعٌ بمخزنين: يُسمّى المخزن ليُعرف أيّهما --}}
                        @if ($hub->branch && ($shared[$hub->branch_id] ?? 0) > 1) — {{ $hub->name }} @endif
                        @if ($count['shipments'] || $count['returns'])
                            <span class="ms-1">· <span class="num">{{ number_format($count['shipments']) }}</span> شحنة
                                · <span class="num">{{ number_format($count['returns']) }}</span> راجع</span>
                        @endif
                    </a>
                @endforeach
            </div>

            @if ($to)
                <form method="POST" action="{{ route('transfers.send') }}" class="border-t border-ink-200">
                    @csrf
                    {{-- بالمسح: يُختار الممسوح وحده، وما ليس في القائمتين يُضاف إلى «أرقام أخرى» --}}
                    @can('shipments.view')
                        <x-scan-box :lookup="route('shipments.scan.lookup')" param="number" only append="#numbers"
                                    class="!mb-0 rounded-none border-0 border-b border-ink-100 shadow-none"
                                    hint="امسح ما تُرسله — الباركود أو رمز QR — فيُختار وحده، ثم «أرسل»." />
                    @endcan
                    <input type="hidden" name="to_hub_id" value="{{ $to->id }}">
                    @if ($canChooseHub)
                        <input type="hidden" name="from_hub_id" value="{{ $here->id }}">
                    @endif

                    @foreach ([['returns', $returns, 'رواجع لتجّار '.($to->branch?->name ?? $to->name), 'تسافر راجعةً وتصل جاهزةً لتسليم تاجرها — لا شحنةً جديدة.'],
                               ['shipments', $shipments, 'شحنات إلى محافظة '.($to->branch?->name ?? $to->name), 'تدخل مخزن الفرع هناك وتنتظر مندوب توصيل منه.']] as [$key, $rows, $title, $hint])
                        @if ($rows->isNotEmpty())
                            <div data-check-scope class="border-b border-ink-100">
                                <div class="flex flex-wrap items-center justify-between gap-2 px-5 pt-4">
                                    <div>
                                        <h3 class="font-semibold">{{ $title }} <span class="num text-ink-500">({{ number_format($counts->get($to->id)[$key] ?? $rows->count()) }})</span></h3>
                                        @if (($counts->get($to->id)[$key] ?? 0) > $rows->count())
                                            <p class="text-xs text-warn-700">يظهر أقدم {{ number_format($rows->count()) }} منها؛ أرسلها ثم يظهر الباقي.</p>
                                        @endif
                                        <p class="text-xs text-ink-500">{{ $hint }}</p>
                                    </div>
                                    <label class="flex items-center gap-2 text-sm">
                                        <input type="checkbox" checked data-check-all class="size-4 accent-[var(--brand)]"> الكل
                                    </label>
                                </div>
                                {{-- القائمة تطول بمئات الشحنات: تُمرَّر في مكانها فيبقى زرّ الإرسال قريباً --}}
                                <div class="max-h-[26rem] overflow-auto">
                                    <table class="tbl">
                                        <thead class="sticky top-0 z-10 bg-white">
                                            <tr>
                                                <th class="w-10"></th>
                                                <th>الوصل</th>
                                                <th>التاجر</th>
                                                <th>{{ $key === 'returns' ? 'سبب الرجوع' : 'المنطقة' }}</th>
                                                <th>الحالة</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($rows as $shipment)
                                                <tr>
                                                    <td><input type="checkbox" name="shipment_ids[]" value="{{ $shipment->id }}" checked
                                                               aria-label="اختر {{ $shipment->number }}" class="size-4 accent-[var(--brand)]"></td>
                                                    <td class="whitespace-nowrap"><a href="{{ route('shipments.show', $shipment) }}" class="num font-semibold hover:underline">{{ $shipment->number }}</a></td>
                                                    <td class="text-ink-600">{{ $shipment->merchant?->business_name }}</td>
                                                    <td class="text-xs text-ink-600">
                                                        {{ $key === 'returns' ? ($shipment->returnReason() ?? '—') : ($shipment->city?->name_ar ?? '—') }}
                                                    </td>
                                                    <td><x-status-badge :status="$shipment->status" :shipment="$shipment" /></td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endif
                    @endforeach

                    @if ($returns->isEmpty() && $shipments->isEmpty())
                        <p class="px-5 py-4 text-sm text-ink-500">
                            لا شحنة على رفّك لمحافظة هذا الفرع ولا راجع لتجّاره الآن. وما تريد إرساله غير ذلك امسحه أدناه.
                        </p>
                    @endif

                    <div class="grid grid-cols-1 gap-4 px-5 py-4 lg:grid-cols-2">
                        <div>
                            <label class="field-label" for="numbers">امسح أو اكتب أرقاماً أخرى (وصلٌ في كل سطر)</label>
                            <textarea id="numbers" name="numbers" rows="5" class="field-input num"
                                      placeholder="امسح الباركود هنا…">{{ old('numbers') }}</textarea>
                            @error('shipment_ids') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <div class="space-y-3">
                            {{-- المناورة: مندوب نقلٍ من مندوبي الشركة، فتُعرف الشحنة معه في الطريق --}}
                            <div>
                                <label class="field-label" for="courier_id">من يحملها؟ مندوب النقل بين الفروع</label>
                                <select id="courier_id" name="courier_id" class="field-input">
                                    <option value="">سائقٌ من خارج الشركة (أدناه)</option>
                                    @foreach ($carriers as $carrier)
                                        <option value="{{ $carrier->id }}" @selected((int) old('courier_id') === $carrier->id)>
                                            {{ $carrier->name }} — {{ $carrier->phone }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('courier_id') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <div>
                                    <label class="field-label" for="driver_name">أو اسم السائق</label>
                                    <input id="driver_name" name="driver_name" type="text" maxlength="160" class="field-input"
                                           value="{{ old('driver_name') }}">
                                </div>
                                <div>
                                    <label class="field-label" for="driver_phone">هاتفه</label>
                                    <input id="driver_phone" name="driver_phone" type="text" maxlength="20" class="field-input num"
                                           value="{{ old('driver_phone') }}">
                                </div>
                                <div>
                                    <label class="field-label" for="vehicle_number">رقم المركبة</label>
                                    <input id="vehicle_number" name="vehicle_number" type="text" maxlength="40" class="field-input"
                                           value="{{ old('vehicle_number') }}">
                                </div>
                            </div>
                            @error('driver_name') <p class="field-error">{{ $message }}</p> @enderror
                            @error('driver_phone') <p class="field-error">{{ $message }}</p> @enderror
                            @error('to_hub_id') <p class="field-error">{{ $message }}</p> @enderror
                            <button type="submit" class="btn-primary w-full">أرسل الآن إلى {{ $to->branch?->name ?? $to->name }}</button>
                            <p class="text-xs text-ink-500">يُطبع كشف النقل بعد الإرسال ليحمله السائق ويوقّعه المستلم.</p>
                        </div>
                    </div>
                </form>
            @endif
        @endif
    </section>

    {{-- ٣) في الطريق منك --}}
    @if ($outgoing->isNotEmpty())
        <section class="card mb-5 overflow-hidden">
            <div class="border-b border-ink-200 px-5 py-4">
                <h2 class="card-title">في الطريق منك <span class="num ms-1 text-ink-500">({{ number_format($outgoing->count()) }})</span></h2>
                <p class="card-hint">أُرسلت ولم يستلمها الفرع الآخر بعد.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead>
                        <tr><th>الكشف</th><th>إلى</th><th>يحمله</th><th>شحنات</th><th>غادر</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($outgoing as $manifest)
                            @php $days = $manifest->departed_at ? (int) $manifest->departed_at->diffInDays(now()) : 0; @endphp
                            <tr>
                                <td class="whitespace-nowrap"><a href="{{ route('manifests.show', $manifest) }}" class="num font-semibold hover:underline">{{ $manifest->code }}</a></td>
                                <td class="text-ink-600">{{ $manifest->toHub?->name }}</td>
                                <td class="text-ink-600">{{ $manifest->courier?->name ?? $manifest->driver_name ?? '—' }}</td>
                                <td class="num">{{ number_format($manifest->shipments_count) }}</td>
                                <td>
                                    <span class="chip {{ $days >= 2 ? 'chip-bad' : 'chip-mute' }}">
                                        {{ $days === 0 ? 'اليوم' : \App\Support\Arabic::days($days) }}
                                    </span>
                                </td>
                                <td class="text-end"><a href="{{ route('manifests.print', $manifest) }}" target="_blank" class="btn-ghost">اطبع الكشف</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <p class="text-xs text-ink-500">
        لتقسيم الحمولة على أكياسٍ بيدك:
        <a href="{{ route('bags.index') }}" class="underline">الأكياس</a> ·
        <a href="{{ route('manifests.index') }}" class="underline">كشوف النقل</a>
    </p>
@endif
@endsection
