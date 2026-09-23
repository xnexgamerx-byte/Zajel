@extends('layouts.app')
@section('title', 'اعتراضات حصص الاستلام')

@section('content')
<div class="mb-5">
    <h1 class="page-title">اعتراضات حصص الاستلام</h1>
    <p class="mt-1 text-sm text-ink-500">
        نظام حوافز بآلية تظلّم: «جمعتُ أربعين طرداً واحتسبتم لي ثلاثين» خلافٌ له أثر مكتوب هنا لا مكالمة تُنسى.
    </p>
</div>

@if ($open->isEmpty())
    <section class="card p-10 text-center">
        <p class="text-ink-500">لا اعتراضات معلّقة.</p>
    </section>
@else
    <div class="space-y-4">
        @foreach ($open as $share)
            <section class="card border-warn-200 p-5">
                <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="font-bold">{{ $share->courier?->name }}</div>
                        <div class="text-sm text-ink-500">
                            طلب <span class="num">{{ $share->pickupRequest?->number ?? '—' }}</span>
                            · اعترض {{ $share->objected_at?->format('Y-m-d H:i') }}
                        </div>
                    </div>
                    <div class="flex gap-4 text-center">
                        <div>
                            <div class="text-xs text-ink-500">احتُسب له</div>
                            <div class="num text-lg font-bold">{{ number_format($share->shipments_count) }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-ink-500">يقول إنه جمع</div>
                            <div class="num text-lg font-bold text-warn-700">{{ number_format($share->claimed_count) }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-ink-500">الحصّة للطرد</div>
                            <div class="num text-lg font-bold">{{ number_format($share->rate) }}</div>
                        </div>
                    </div>
                </div>

                <p class="mb-4 rounded-lg bg-warn-50 px-3 py-2 text-sm text-warn-700">
                    {{ $share->objection_reason }}
                </p>

                <form method="POST" action="{{ route('pickup-agents.resolve', $share) }}"
                      class="flex flex-wrap items-end gap-3">
                    @csrf
                    <div>
                        <label class="field-label" for="decision-{{ $share->id }}">القرار</label>
                        <select id="decision-{{ $share->id }}" name="decision" class="field-input" required
                                onchange="this.closest('form').querySelector('[data-agreed]').hidden = this.value !== 'accept'">
                            <option value="accept">أقبل — عدّل العدد</option>
                            <option value="reject">أرفض</option>
                        </select>
                    </div>
                    <div data-agreed>
                        <label class="field-label" for="agreed-{{ $share->id }}">العدد المُقَرّ</label>
                        <input id="agreed-{{ $share->id }}" name="agreed_count" type="number" min="0" step="1"
                               class="field-input num w-32" value="{{ $share->claimed_count }}">
                    </div>
                    <div class="min-w-64 flex-1">
                        <label class="field-label" for="note-{{ $share->id }}">سبب القرار</label>
                        <input id="note-{{ $share->id }}" name="note" type="text" maxlength="255" required
                               class="field-input" placeholder="راجعنا وصولات الطلب فوُجدت ٣٨">
                    </div>
                    <button type="submit" class="btn-primary">احسم</button>
                </form>
            </section>
        @endforeach
    </div>
@endif

@if ($resolved->isNotEmpty())
    <section class="card mt-5 overflow-hidden">
        <h2 class="card-title border-b border-ink-100 px-5 py-4">اعتراضات مَحسومة</h2>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr><th>المندوب</th><th>الطلب</th><th>احتُسب</th><th>طالب بـ</th><th>الفرق</th><th>القرار</th></tr>
                </thead>
                <tbody>
                    @foreach ($resolved as $share)
                        <tr>
                            <td class="font-medium">{{ $share->courier?->name }}</td>
                            <td class="num">{{ $share->pickupRequest?->number ?? '—' }}</td>
                            <td class="num">{{ number_format($share->shipments_count) }}</td>
                            <td class="num">{{ number_format($share->claimed_count ?? 0) }}</td>
                            <td class="num {{ $share->adjustment > 0 ? 'text-ok-700' : ($share->adjustment < 0 ? 'text-bad-700' : 'text-ink-400') }}">
                                {{ $share->adjustment ? ($share->adjustment > 0 ? '+' : '').number_format($share->adjustment) : '—' }}
                            </td>
                            <td>
                                <span class="chip {{ $share->statusTone() }}">{{ $share->statusLabel() }}</span>
                                <div class="mt-1 text-xs text-ink-500">{{ $share->resolution_note }}</div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif
@endsection
