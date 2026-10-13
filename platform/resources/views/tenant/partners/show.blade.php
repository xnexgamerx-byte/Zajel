@extends('layouts.app')
@section('title', $partner->name)

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <a href="{{ route('partners.index') }}" class="text-sm text-ink-500 hover:underline">← الشركاء</a>
        <h1 class="page-title">{{ $partner->name }}</h1>
        <p class="mt-1 text-sm text-ink-500">
            بيده الآن <span class="num font-semibold">{{ number_format($held->count()) }}</span> طرد.
            الشريك يرى طروده ويحدّث حالاتها بمفتاحه، ولا يرى مبالغنا ولا كشوفنا ولا تجّارنا.
        </p>
    </div>
</div>

{{-- المفتاح كاملاً يظهر مرّةً واحدة بعد إنشائه --}}
@if (session('partner_token'))
    <div class="card mb-5 border-ok-200 bg-ok-50 p-4">
        <p class="card-title mb-2">انسخ المفتاح الآن، لن يظهر كاملاً مرّةً أخرى:</p>
        <code class="num block break-all rounded-lg bg-white p-3 text-sm" dir="ltr">{{ session('partner_token') }}</code>
    </div>
@endif

@if (session('handover'))
    @php $result = session('handover') @endphp
    @if (! empty($result['skipped']))
        <div class="card mb-5 border-warn-200 bg-warn-50 p-4 text-sm text-warn-700">
            <p class="font-semibold mb-1">لم تُسلَّم هذه الطرود:</p>
            <ul class="list-disc ps-5">
                @foreach ($result['skipped'] as $number => $why)
                    <li><span class="num">{{ $number }}</span>: {{ $why }}</li>
                @endforeach
            </ul>
        </div>
    @endif
@endif

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <div class="space-y-5 lg:col-span-2">
        {{-- تقارير التسليم: لا تُحسب قبل موافقتنا --}}
        <section class="card overflow-hidden">
            <div class="border-b border-ink-100 px-5 py-3">
                <h2 class="card-title">تقارير تسليم بانتظار موافقتنا</h2>
                <p class="card-hint">لا يُحسب مال الطرد قبل أن توافق. الموافقة تسلّمه فعلاً.</p>
            </div>
            @forelse ($reports as $report)
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-ink-100 px-5 py-3 last:border-0">
                    <div class="text-sm">
                        <span class="num font-semibold">{{ $report->shipment->number }}</span>
                        · {{ $report->shipment->recipient_name }}
                        <div class="text-xs text-ink-500">
                            بلّغ {{ $report->key->name }} {{ $report->reported_at->diffForHumans() }}@if ($report->note) — «{{ $report->note }}»@endif
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <form method="POST" action="{{ route('partner-reports.approve', $report) }}">
                            @csrf
                            <button class="btn-primary py-1 text-sm">وافقنا على التسليم</button>
                        </form>
                        <form method="POST" action="{{ route('partner-reports.reject', $report) }}" class="flex items-center gap-1">
                            @csrf
                            <input name="note" required maxlength="500" placeholder="سبب الرفض" class="field-input py-1 text-sm">
                            <button class="btn-ghost py-1 text-sm">ارفض</button>
                        </form>
                    </div>
                </div>
            @empty
                <p class="px-5 py-8 text-center text-sm text-ink-500">لا تقارير تنتظر مراجعتك.</p>
            @endforelse
        </section>

        {{-- الطرود عنده الآن --}}
        <section class="card overflow-hidden">
            <div class="border-b border-ink-100 px-5 py-3">
                <h2 class="card-title">طرود عنده الآن</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th>الرقم</th>
                            <th>المستلم</th>
                            <th>المحافظة</th>
                            <th>حالته عنده</th>
                            <th>آخر تحديث</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($held as $shipment)
                            <tr>
                                <td class="num font-semibold">{{ $shipment->number }}</td>
                                <td>{{ $shipment->recipient_name }}</td>
                                <td>{{ $shipment->governorate?->name_ar }}</td>
                                <td><x-status-badge :status="$shipment->status" /></td>
                                <td class="text-xs text-ink-500">{{ $shipment->status_changed_at?->diffForHumans() }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-10 text-center text-ink-500">لا طرود عنده.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div class="space-y-5">
        {{-- التسليم: أرقام الطرود، من «بالمخزن» فقط --}}
        <section class="card p-5">
            <h2 class="card-title mb-1">سلّم طروداً إليه</h2>
            <p class="card-hint mb-3">اكتب أرقام الطرود اللي في المخزن. ما يُسلَّم إلا ما هو «بالمخزن».</p>
            <form method="POST" action="{{ route('partners.handover', $partner) }}" class="space-y-3">
                @csrf
                <textarea name="numbers" rows="3" required class="field-input num" dir="ltr" placeholder="ZA-20260101 ZA-20260102"></textarea>
                <button class="btn-primary w-full">سلّم للشريك</button>
            </form>
        </section>

        {{-- الراجع من الشريك إلينا --}}
        <section class="card p-5">
            <h2 class="card-title mb-1">استلمنا راجعاً منه</h2>
            <p class="card-hint mb-3">الطرود الراجعة من الشريك إلينا: اكتب أرقامها.</p>
            <form method="POST" action="{{ route('partners.receive-back', $partner) }}" class="space-y-3">
                @csrf
                <textarea name="numbers" rows="2" required class="field-input num" dir="ltr"></textarea>
                <button class="btn-ghost w-full">استلمنا الراجع</button>
            </form>
        </section>

        {{-- مفاتيح الربط --}}
        <section class="card p-5">
            <h2 class="card-title mb-3">مفاتيح الربط</h2>
            <div class="mb-4 space-y-2">
                @forelse ($partner->keys as $key)
                    @php $dead = $key->revoked_at || $key->expires_at->isPast(); @endphp
                    <div class="flex items-center justify-between gap-2 rounded-lg border border-ink-100 p-2 text-sm">
                        <div>
                            <div class="font-semibold">{{ $key->name }}</div>
                            <div class="text-xs text-ink-500">
                                <span class="num" dir="ltr">…{{ $key->token_hint }}</span>
                                · ينتهي {{ $key->expires_at->format('Y-m-d') }}
                                · آخر استعمال: {{ $key->last_used_at?->diffForHumans() ?? 'لم يُستعمل' }}
                            </div>
                        </div>
                        @if ($dead)
                            <span class="chip chip-mute">{{ $key->revoked_at ? 'ملغى' : 'منتهٍ' }}</span>
                        @else
                            <form method="POST" action="{{ route('partners.keys.revoke', [$partner, $key]) }}">
                                @csrf
                                <button class="text-xs text-bad-700" data-confirm="نلغي المفتاح؟ يُرفض فوراً.">ألغِ</button>
                            </form>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-ink-500">لا مفاتيح بعد.</p>
                @endforelse
            </div>

            <form method="POST" action="{{ route('partners.keys.store', $partner) }}" class="space-y-3 border-t border-ink-100 pt-4">
                @csrf
                <label class="block">
                    <span class="field-label">اسم المفتاح</span>
                    <input name="name" required maxlength="120" class="field-input" placeholder="مثلاً: فرع البصرة">
                </label>
                <fieldset>
                    <legend class="field-label mb-1">الصلاحيات</legend>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="abilities[]" value="shipments.read" checked> قراءة طروده وعددها</label>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="abilities[]" value="shipments.status" checked> تحديث حالاتها ومسح استلامها</label>
                </fieldset>
                <label class="block">
                    <span class="field-label">ينتهي في</span>
                    <input type="date" name="expires_at" required value="{{ now()->addMonths(6)->toDateString() }}" class="field-input">
                </label>
                <label class="block">
                    <span class="field-label">عناوين مسموحة (اختياري)</span>
                    <textarea name="ips" rows="2" class="field-input num" dir="ltr" placeholder="203.0.113.10"></textarea>
                </label>
                <button class="btn-primary w-full">أنشئ المفتاح</button>
            </form>
        </section>
    </div>
</div>
@endsection
