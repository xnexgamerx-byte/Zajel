@extends('layouts.app')
@section('title', 'الشحنات')

@section('content')

{{-- ملخّص اليوم --}}
<div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-5">
    @foreach ([
        ['الكل', $totals['total'], 'text-ink-900'],
        ['قيد التنفيذ', $totals['open'], 'text-info-700'],
        ['مسلَّمة', $totals['delivered'], 'text-ok-700'],
        ['راجعة', $totals['returned'], 'text-ink-600'],
    ] as [$label, $value, $tone])
        <div class="card p-4">
            <div class="text-xs font-medium text-ink-500">{{ $label }}</div>
            <div class="mt-1 text-2xl font-bold {{ $tone }}">{{ number_format($value) }}</div>
        </div>
    @endforeach

    <div class="card p-4">
        <div class="text-xs font-medium text-ink-500">مبالغ لم تُحصَّل</div>
        <div class="mt-1 text-2xl font-bold text-warn-700">
                <span class="num">{{ number_format($totals['cod_open']) }}</span>
                <span class="text-sm font-medium text-ink-500">د.ع</span>
        </div>
    </div>
</div>

{{-- البحث والتصفية — كما في «عرض كل شحنات العميل» في المعتاد --}}
<form method="GET" class="card mb-4 p-4">
    @if ($stage)
        <input type="hidden" name="stage" value="{{ request('stage') }}">
    @endif

    <div class="grid grid-cols-1 gap-3 md:grid-cols-4 lg:grid-cols-6">
        <div class="lg:col-span-2">
            <label class="field-label" for="q">بحث</label>
            <input id="q" name="q" value="{{ request('q') }}" class="field-input"
                   placeholder="رقم وصل · باركود · هاتف المستلم · رقم طلب التاجر">
        </div>

        <div>
            <label class="field-label" for="status">الحالة</label>
            <select id="status" name="status" class="field-input">
                <option value="">الكل</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(request('status') === $status->value)>
                        {{ $status->label() }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="field-label" for="merchant_id">التاجر</label>
            <select id="merchant_id" name="merchant_id" class="field-input" data-searchable>
                <option value="">الكل</option>
                @foreach ($merchants as $merchant)
                    <option value="{{ $merchant->id }}" @selected((int) request('merchant_id') === $merchant->id)>
                        {{ $merchant->business_name }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="field-label" for="governorate_id">المحافظة</label>
            <select id="governorate_id" name="governorate_id" class="field-input">
                <option value="">الكل</option>
                @foreach ($governorates as $gov)
                    <option value="{{ $gov->id }}" @selected((int) request('governorate_id') === $gov->id)>
                        {{ $gov->name_ar }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="field-label" for="courier_id">مندوب التوصيل</label>
            <select id="courier_id" name="courier_id" class="field-input">
                <option value="">الكل</option>
                @foreach ($couriers as $courier)
                    <option value="{{ $courier->id }}" @selected((int) request('courier_id') === $courier->id)>
                        {{ $courier->name }}
                    </option>
                @endforeach
            </select>
        </div>
    </div>

    <details class="mt-3" @if ($advanced) open @endif>
        <summary class="cursor-pointer text-sm font-medium text-[var(--brand)]">بحث متقدّم</summary>
        <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-4 lg:grid-cols-6">
            <div>
                <label class="field-label" for="city_id">المنطقة</label>
                <select id="city_id" name="city_id" class="field-input" data-searchable
                        data-empty-label="الكل" data-old="{{ request('city_id') }}">
                    <option value="">الكل</option>
                </select>
            </div>
            <div>
                <label class="field-label" for="pickup_courier_id">مندوب الاستلام</label>
                <select id="pickup_courier_id" name="pickup_courier_id" class="field-input">
                    <option value="">الكل</option>
                    @foreach ($pickupCouriers as $courier)
                        <option value="{{ $courier->id }}" @selected((int) request('pickup_courier_id') === $courier->id)>{{ $courier->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="field-label" for="branch_id">أُنشئت في فرع</label>
                <select id="branch_id" name="branch_id" class="field-input">
                    <option value="">الكل</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}" @selected((int) request('branch_id') === $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="field-label" for="current_branch_id">حالياً في فرع</label>
                <select id="current_branch_id" name="current_branch_id" class="field-input">
                    <option value="">الكل</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}" @selected((int) request('current_branch_id') === $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="field-label" for="reason_id">سبب الراجع أو التأجيل</label>
                <select id="reason_id" name="reason_id" class="field-input">
                    <option value="">الكل</option>
                    @foreach ($reasons as $reason)
                        <option value="{{ $reason->id }}" @selected((int) request('reason_id') === $reason->id)>{{ $reason->name_ar }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="field-label" for="type">النوع</label>
                <select id="type" name="type" class="field-input">
                    <option value="">الكل</option>
                    @foreach (['delivery' => 'توصيل', 'exchange' => 'استبدال', 'return' => 'إرجاع'] as $value => $label)
                        <option value="{{ $value }}" @selected(request('type') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="field-label" for="settled">تمّ التحاسب مع التاجر؟</label>
                <select id="settled" name="settled" class="field-input">
                    <option value="">الكل</option>
                    <option value="yes" @selected(request('settled') === 'yes')>نعم</option>
                    <option value="no" @selected(request('settled') === 'no')>لا</option>
                </select>
            </div>
            <div>
                <label class="field-label" for="amount">مبلغ الوصل</label>
                <input id="amount" name="amount" value="{{ request('amount') }}" class="field-input" inputmode="numeric" dir="ltr">
            </div>
            <div>
                <label class="field-label" for="stage_from">دخل المرحلة من</label>
                <input id="stage_from" type="date" name="stage_from" value="{{ request('stage_from') }}" class="field-input">
            </div>
            <div>
                <label class="field-label" for="stage_to">دخل المرحلة إلى</label>
                <input id="stage_to" type="date" name="stage_to" value="{{ request('stage_to') }}" class="field-input">
            </div>
        </div>
    </details>

    <div class="mt-3 flex flex-wrap items-end gap-3">
        <div>
            <label class="field-label" for="from">أُنشئت من</label>
            <input id="from" type="date" name="from" value="{{ request('from') }}" class="field-input">
        </div>
        <div>
            <label class="field-label" for="to">إلى</label>
            <input id="to" type="date" name="to" value="{{ request('to') }}" class="field-input">
        </div>

        <button type="submit" class="btn-primary">تطبيق</button>
        <a href="{{ route('shipments.index') }}" class="btn-ghost">مسح</a>

        @can('shipments.export')
            <span class="ms-auto flex flex-wrap gap-2">
                <a href="{{ route('shipments.export', $filters) }}" class="btn-ghost">
                    <x-icon name="download" class="size-5"/> Excel
                </a>
                <a href="{{ route('shipments.export.print', $filters) }}" class="btn-ghost" target="_blank" rel="noopener">
                    <x-icon name="printer" class="size-5"/> PDF
                </a>
            </span>
        @endcan
    </div>
</form>

@if ($stage)
    <div class="mb-3 flex flex-wrap items-center gap-2 text-sm">
        <span class="text-ink-500">المرحلة:</span>
        <span class="chip chip-info">{{ $stage['group'] }} › {{ $stage['label'] }}</span>
        <a href="{{ route('shipments.index', \Illuminate\Support\Arr::except($filters, 'stage')) }}" class="text-xs font-semibold text-[var(--brand)] hover:underline">أزلها</a>
        <a href="{{ route('shipments.stages') }}" class="text-xs text-ink-500 hover:underline">كل المراحل</a>
    </div>
@endif

{{-- الجدول --}}
<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th class="w-10 px-4 py-3 text-start">
                        <input type="checkbox" data-select-all
                               class="rounded border-ink-300 text-[var(--brand)] focus:ring-brand-500">
                    </th>
                    <th >رقم الوصل</th>
                    <th >التاجر</th>
                    <th >المستلم</th>
                    <th >الوجهة</th>
                    <th >المبلغ</th>
                    <th >الأجرة</th>
                    <th >المندوب</th>
                    <th >الحالة</th>
                    <th >التاريخ</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($shipments as $shipment)
                    <tr>
                        <td class="px-4 py-3">
                            @if (auth()->user()->isStaff())
                                <input type="checkbox" form="assign-form" name="shipment_ids[]"
                                       value="{{ $shipment->id }}" data-row-select
                                       class="rounded border-ink-300 text-[var(--brand)] focus:ring-brand-500">
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <a href="{{ route('shipments.show', $shipment) }}"
                               class="font-mono font-semibold text-[var(--brand)] hover:underline" dir="ltr">
                                {{ $shipment->number }}
                            </a>
                            @if ($shipment->attempts_count > 0)
                                <span class="ms-1 rounded bg-warn-50 px-1.5 text-xs font-semibold text-warn-700">
                                    {{ $shipment->attempts_count }} محاولة
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-ink-700">{{ $shipment->merchant->business_name }}</td>
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ $shipment->recipient_name }}</div>
                            <div class="text-xs text-ink-500">
                                <x-phone :number="$shipment->recipient_phone" :name="$shipment->recipient_name" />
                            </div>
                        </td>
                        <td class="px-4 py-3 text-ink-700">
                            {{ $shipment->governorate->name_ar }}
                            @if ($shipment->city)
                                <span class="text-ink-400">·</span>
                                <span class="text-xs text-ink-500">{{ $shipment->city->name_ar }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 font-semibold" dir="ltr">{{ number_format($shipment->cod_amount) }}</td>
                        <td class="px-4 py-3 text-ink-600" dir="ltr">{{ number_format($shipment->total_fees) }}</td>
                        <td class="px-4 py-3 text-ink-700">
                            {{ $shipment->deliveryCourier?->name ?? '—' }}
                        </td>
                        <td class="px-4 py-3">
                            <x-status-badge :status="$shipment->status" />
                        </td>
                        <td class="px-4 py-3 text-xs text-ink-500" dir="ltr">
                            {{ $shipment->created_at->format('Y-m-d H:i') }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="px-4 py-16 text-center">
                            <div class="text-ink-500">لا توجد شحنات مطابقة.</div>
                            @if (auth()->user()->isStaff())
                                <a href="{{ route('shipments.create') }}" class="btn-primary mt-4">أنشئ أول شحنة</a>
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($shipments->hasPages())
        <div class="border-t border-ink-100 px-4 py-3">
            {{ $shipments->links() }}
        </div>
    @endif
</div>

<p class="mt-3 mb-20 text-xs text-ink-500">
    إجمالي النتائج: {{ number_format($shipments->total()) }}
</p>

{{-- شريط الإسناد الجماعي: يظهر عند اختيار صفوف --}}
@if (auth()->user()->isStaff())
<form method="POST" action="{{ route('shipments.assign') }}" id="assign-form"
      class="fixed inset-x-0 bottom-0 z-40 border-t border-ink-200 bg-white/95 px-4 py-3 shadow-lg backdrop-blur"
      hidden data-bulk-bar>
    @csrf
    <div class="mx-auto flex max-w-screen-2xl flex-wrap items-center gap-3">
        <span class="text-sm font-semibold">
            <span data-bulk-count>0</span> شحنة مختارة
        </span>

        {{-- تغطية المندوب بجانب اسمه: التوزيع الصباحي يُصيب من أول مرّة --}}
        <select name="courier_id" class="field-input w-auto min-w-64" required>
            <option value="">اختر المندوب</option>
            @foreach ($couriers as $courier)
                @php $covers = $courier->zones->pluck('governorate.name_ar')->filter()->unique(); @endphp
                <option value="{{ $courier->id }}">
                    {{ $courier->name }}{{ $covers->isNotEmpty() ? ' — '.$covers->take(3)->implode('، ') : ' — بلا مناطق' }}
                </option>
            @endforeach
        </select>

        <button type="submit" class="btn-primary">إسناد وإخراج للتوصيل</button>
        {{-- الطباعة رابطٌ لا نموذج: نموذج GET كان سيحمل رمز الحماية في العنوان --}}
        <button type="button" class="btn-ghost" data-bulk-print="{{ route('shipments.labels') }}">
            <x-icon name="printer" class="size-5"/>
            طباعة الوصولات
        </button>
        <button type="button" class="btn-ghost" data-bulk-clear>إلغاء الاختيار</button>

        <span class="ms-auto text-xs text-ink-500">
            الشحنات التي لا تسمح حالتها بالإسناد تُتخطّى ويُبلَّغ بها.
        </span>
    </div>
</form>
@endif
@php
    $citiesByGovernorate = $cities->groupBy('governorate_id')
        ->map(fn ($group) => $group->map(fn ($c) => ['id' => $c->id, 'name' => $c->name_ar])->values());
@endphp
<script type="application/json" id="cities-data">@json($citiesByGovernorate)</script>
@endsection
