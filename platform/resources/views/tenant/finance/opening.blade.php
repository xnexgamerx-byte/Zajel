@extends('layouts.app')
@section('title', 'الأرصدة الافتتاحية')

@use('App\Services\Finance\OpeningBalances')

@section('content')
@include('tenant.finance._tabs', [
    'title' => 'الأرصدة الافتتاحية',
    'lead' => 'ما لا يُعاد بناؤه من التاريخ — رصيدٌ أُدخل قبل الدفتر أو حركةٌ قبل ميزةٍ بعينها — يُقيَّد مرّةً يوم التشغيل بقيدٍ افتتاحي طرفه «رأس المال والأرصدة الافتتاحية» (3100). وبعده يطابق كل رصيدٍ قيوده.',
])

@error('opening') <div class="alert alert-bad mb-4">{{ $message }}</div> @enderror

@unless ($initialized)
    <div class="alert alert-warn mb-4">لم يكتمل بناء الدفتر من التاريخ بعد: الفروق أدناه قد تتغيّر حين يكتمل. انتظره دقائق.</div>
@endunless

@if ($rows->isEmpty())
    <div class="card mb-5 px-4 py-10 text-center text-ink-500">كل صندوقٍ ومندوبٍ وتاجرٍ يطابق رصيده قيوده: لا حاجة لقيدٍ افتتاحي.</div>
@else
    @foreach (OpeningBalances::KINDS as $kind => $label)
        @continue(! $rows->has($kind))
        <section class="card mb-4 overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-ink-100 px-5 py-3">
                <h2 class="card-title">{{ $label }}</h2>
                <span class="text-sm text-ink-600">الفرق <span class="num font-bold text-ink-900">{{ number_format($rows[$kind]->sum('diff')) }}</span> د.ع</span>
            </div>
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead><tr><th>الاسم</th><th>الرصيد المسجَّل</th><th>رصيد القيود</th><th>سطر القيد</th></tr></thead>
                    <tbody>
                        @foreach ($rows[$kind] as $row)
                            <tr>
                                <td>{{ $row['name'] }}</td>
                                <td class="num">{{ number_format($row['stored']) }}</td>
                                <td class="num">{{ number_format($row['journal']) }}</td>
                                <td class="num font-semibold">{{ $row['diff'] > 0 ? 'مدين' : 'دائن' }} {{ number_format(abs($row['diff'])) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endforeach

    @can('periods.close')
        <form method="POST" action="{{ route('finance.opening.store') }}" class="card mb-5 flex flex-wrap items-end gap-3 p-5"
              data-confirm="يُكتب قيدٌ افتتاحيٌّ واحد بكل هذه الفروق، وطرفه رأس المال (3100). لا يُحذف بعدها، والتصحيح قيدٌ جديد. متابعة؟">
            @csrf
            <div>
                <label class="field-label" for="opened_on">يوم التشغيل</label>
                <input id="opened_on" type="date" name="opened_on" value="{{ old('opened_on', now()->timezone(config('app.timezone'))->toDateString()) }}" class="field-input" required>
                @error('opened_on') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="min-w-64 flex-1">
                <label class="field-label" for="reason">السبب</label>
                <input id="reason" name="reason" maxlength="200" required class="field-input" value="{{ old('reason') }}" placeholder="أرصدةٌ نُقلت من النظام القديم قبل بناء الدفتر">
                @error('reason') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <button class="btn-primary">اكتب القيد الافتتاحي</button>
        </form>
    @else
        <p class="mb-5 text-xs text-ink-500">يكتب القيدَ الافتتاحيَّ صاحبُ الشركة (اعتماد إقفال الفترات).</p>
    @endcan
@endif

@if ($entries->isNotEmpty())
    <section class="card overflow-hidden">
        <h2 class="border-b border-ink-100 px-5 py-3 card-title">القيود الافتتاحية المكتوبة</h2>
        <ul class="divide-y divide-ink-100 text-sm">
            @foreach ($entries as $entry)
                <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-3">
                    <a href="{{ route('finance.entries.show', $entry) }}" class="num font-semibold text-[var(--brand)] hover:underline">قيد {{ $entry->number }}</a>
                    <span class="text-ink-600">{{ $entry->description }}</span>
                    <span class="num text-xs text-ink-500">{{ $entry->entry_date?->format('Y-m-d') }} · {{ $entry->createdBy?->name ?? '—' }} · {{ number_format($entry->amount) }} د.ع</span>
                </li>
            @endforeach
        </ul>
    </section>
@endif
@endsection
