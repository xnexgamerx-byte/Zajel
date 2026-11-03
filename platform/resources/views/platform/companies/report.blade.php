@extends('layouts.platform')
@section('title', 'تقرير '.$company->name)

{{--
  تقرير الشركة لمدير المنصّة (docs/plan/111): ربحها وشحناتها في المدّة، وتقويمها يوماً بيوم، وما حُذف من شحناتها
  وما تغيّر سعره ومن فعله. يقرأ فقط.
--}}
@section('content')
@php
    $s = $report['shipments'];
    $m = $report['money'];
@endphp
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <a href="{{ route('admin.companies.show', $company) }}" class="text-sm font-semibold text-[var(--brand)] hover:underline">→ {{ $company->name }}</a>
        <h1 class="page-title mt-2">تقرير {{ $company->name }}</h1>
        <p class="mt-1 text-sm text-ink-500">من {{ $period->from->toDateString() }} إلى {{ $period->to->toDateString() }} · <span class="num">{{ $period->days() }}</span> يوماً</p>
    </div>
</div>

<form method="GET" class="card mb-5 flex flex-wrap items-end gap-3 p-4">
    <div>
        <label class="field-label" for="from">من</label>
        <input id="from" name="from" type="date" class="field-input" value="{{ $period->from->toDateString() }}">
    </div>
    <div>
        <label class="field-label" for="to">إلى</label>
        <input id="to" name="to" type="date" class="field-input" value="{{ $period->to->toDateString() }}">
    </div>
    <button type="submit" class="btn-primary">طبّق</button>
    <div class="flex flex-wrap gap-1.5">
        @foreach ($presets as $label => [$from, $to])
            <a href="{{ route('admin.companies.report', [$company, 'from' => $from->toDateString(), 'to' => $to->toDateString()]) }}"
               @class(['chip', 'chip-info' => $period->from->isSameDay($from) && $period->to->isSameDay($to), 'chip-mute' => ! ($period->from->isSameDay($from) && $period->to->isSameDay($to))])>{{ $label }}</a>
        @endforeach
    </div>
</form>

{{-- الأرقام --}}
<div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
    <div class="card p-4">
        <div class="text-xs text-ink-500">صافي الربح</div>
        @if ($m['ready'])
            <div class="num mt-1 text-2xl font-bold {{ $m['total'] < 0 ? 'text-bad-700' : '' }}">{{ number_format($m['total']) }} <span class="text-sm font-normal">د.ع</span></div>
            <div class="mt-1 text-xs text-ink-500">أجور <span class="num">{{ number_format($m['fees']) }}</span> − تكاليف <span class="num">{{ number_format($m['costs']) }}</span></div>
        @else
            <div class="mt-1 text-sm text-ink-500">دفترها لم يُبنَ بعد.</div>
        @endif
    </div>
    <div class="card p-4">
        <div class="text-xs text-ink-500">شحنات أُنشئت</div>
        <div class="num mt-1 text-2xl font-bold">{{ number_format($s['created']) }}</div>
    </div>
    <div class="card p-4">
        <div class="text-xs text-ink-500">سُلّمت</div>
        <div class="num mt-1 text-2xl font-bold">{{ number_format($s['delivered']) }}</div>
        <div class="mt-1 text-xs text-ink-500">حُصّل منها <span class="num">{{ number_format($s['cod']) }}</span> د.ع</div>
    </div>
    <div class="card p-4">
        <div class="text-xs text-ink-500">رجعت · حُذفت</div>
        <div class="num mt-1 text-2xl font-bold">{{ number_format($s['returned']) }} · <span class="{{ $s['deleted'] ? 'text-bad-700' : '' }}">{{ number_format($s['deleted']) }}</span></div>
    </div>
</div>

{{-- التقويم: يوماً بيوم --}}
<section class="card mb-5 p-5">
    <h2 class="card-title">التقويم</h2>
    @if ($report['calendar'] === null)
        <p class="mt-2 text-sm text-ink-500">المدّة أطول من ٩٢ يوماً: اختر مدّةً أقصر ليظهر يوماً بيوم.</p>
    @else
        @php $peak = max(1, collect($report['calendar'])->max(fn ($d) => max($d['created'], $d['delivered']))); @endphp
        <p class="mt-1 text-xs text-ink-500"><span class="inline-block size-2.5 rounded-sm bg-primary-500"></span> أُنشئت · <span class="inline-block size-2.5 rounded-sm bg-ok-700"></span> سُلّمت</p>
        <div class="mt-3 grid grid-cols-4 gap-2 sm:grid-cols-7">
            @foreach ($report['calendar'] as $day)
                <div class="rounded-lg p-2 ring-1 ring-ink-100 {{ $day['date']->isToday() ? 'ring-2 ring-[var(--brand)]' : '' }}" title="{{ $day['date']->toDateString() }}">
                    <div class="text-[11px] text-ink-500">{{ $day['date']->locale('ar')->translatedFormat('D') }} <span class="num">{{ $day['date']->format('m/d') }}</span></div>
                    <div class="mt-1 flex h-10 items-end gap-1" aria-hidden="true">
                        <span class="w-1/2 rounded-t bg-primary-500" style="height: {{ max(2, round($day['created'] / $peak * 40)) }}px"></span>
                        <span class="w-1/2 rounded-t bg-ok-700" style="height: {{ max(2, round($day['delivered'] / $peak * 40)) }}px"></span>
                    </div>
                    <div class="mt-1 text-xs"><span class="num font-semibold">{{ $day['created'] }}</span> · <span class="num text-ok-700">{{ $day['delivered'] }}</span></div>
                </div>
            @endforeach
        </div>
    @endif
</section>

{{-- ما تغيّر سعره --}}
<section class="card mb-5 p-5">
    <h2 class="card-title">شحناتٌ تغيّر سعرها <span class="num text-ink-500">({{ $report['prices']->count() }})</span></h2>
    @if ($report['prices']->isEmpty())
        <p class="mt-2 text-sm text-ink-500">لم يتغيّر سعر شحنةٍ في هذه المدّة.</p>
    @else
        <div class="mt-3 overflow-x-auto">
            <table class="tbl">
                <thead><tr><th>الوقت</th><th>الشحنة</th><th>التاجر</th><th>ما تغيّر</th><th>كيف</th><th>من</th></tr></thead>
                <tbody class="divide-y divide-ink-100">
                    @foreach ($report['prices'] as $r)
                        <tr>
                            <td class="num text-xs text-ink-500" dir="ltr">{{ $r['at']?->format('Y-m-d H:i') }}</td>
                            <td class="num font-semibold">{{ $r['number'] ?? '—' }}</td>
                            <td>{{ $r['merchant'] ?? '—' }}</td>
                            <td class="text-sm">{{ $r['what'] }}</td>
                            <td class="text-xs">{{ $r['how'] }}</td>
                            <td class="text-xs">{{ $r['by'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

{{-- ما حُذف --}}
<section class="card p-5">
    <h2 class="card-title">شحناتٌ حُذفت <span class="num text-ink-500">({{ $report['deleted']->count() }})</span></h2>
    @if ($report['deleted']->isEmpty())
        <p class="mt-2 text-sm text-ink-500">لم تُحذف شحنةٌ في هذه المدّة.</p>
    @else
        <div class="mt-3 overflow-x-auto">
            <table class="tbl">
                <thead><tr><th>الوقت</th><th>الشحنة</th><th>التاجر</th><th>المبلغ</th><th>حالتها حين حُذفت</th><th>من</th><th>السبب</th></tr></thead>
                <tbody class="divide-y divide-ink-100">
                    @foreach ($report['deleted'] as $r)
                        <tr>
                            <td class="num text-xs text-ink-500" dir="ltr">{{ $r['at']?->format('Y-m-d H:i') }}</td>
                            <td class="num font-semibold">{{ $r['number'] }}</td>
                            <td>{{ $r['merchant'] ?? '—' }}</td>
                            <td class="num">{{ number_format($r['amount']) }}</td>
                            <td class="text-xs">{{ $r['status'] }}</td>
                            <td class="text-xs">{{ $r['by'] ?? '—' }}</td>
                            <td class="text-xs">{{ $r['reason'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    <p class="mt-3 text-xs text-ink-500">تُعرض حتى <span class="num">{{ \App\Services\Platform\CompanyReport::LIST_LIMIT }}</span> في كلّ قائمة، الأحدث أوّلاً.</p>
</section>
@endsection
