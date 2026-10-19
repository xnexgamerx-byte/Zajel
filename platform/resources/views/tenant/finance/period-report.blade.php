@extends('layouts.print', ['back' => route('finance.periods.show', $period)])
@section('title', 'تقرير الفترة '.$period->number.' — النسخة '.$report->version)
@section('docTitle', 'تقرير إقفال الفترة')
@section('subtitle', $period->number.' · '.$period->label().' · النسخة '.$report->version)

@section('content')
<section class="text-sm">
    <p class="mb-4 text-xs text-ink-600">
        أعدّها {{ $period->preparedBy?->name ?? '—' }} · اعتمدها {{ $period->approvedBy?->name ?? '—' }} <span class="num">{{ $period->approved_at?->format('Y-m-d H:i') }}</span>
        · صدرت هذه النسخة <span class="num">{{ $report->issued_at?->format('Y-m-d H:i') }}</span> بيد {{ $report->issuedBy?->name ?? '—' }} — {{ $report->reason }}
    </p>

    @include('tenant.finance._period-figures', ['figures' => $report->figures])

    @if (! empty($report->adjustments['entries']))
        <h2 class="mb-2 mt-5 font-bold">تعديلاتٌ بعد الإقفال</h2>
        <table class="tbl mb-3 w-full">
            <thead><tr><th>القيد</th><th>التاريخ</th><th>البيان</th><th>المبلغ</th></tr></thead>
            <tbody>
                @foreach ($report->adjustments['entries'] as $e)
                    <tr><td class="num">{{ $e['number'] }}</td><td class="num">{{ $e['entry_date'] }}</td><td>{{ $e['description'] }}</td><td class="num">{{ number_format($e['amount']) }}</td></tr>
                @endforeach
            </tbody>
        </table>
        <div class="grid grid-cols-3 gap-3">
            @foreach ([['الإيراد بعد التعديلات', 'revenue'], ['التكاليف بعد التعديلات', 'costs'], ['الصافي بعد التعديلات', 'net']] as [$label, $key])
                <div class="rounded-lg border border-ink-200 p-2"><div class="text-xs text-ink-500">{{ $label }}</div>
                    <div class="num font-bold">{{ number_format($report->figures['after_adjustments'][$key] ?? 0) }}</div></div>
            @endforeach
        </div>
    @endif

    <div class="mt-10 grid grid-cols-2 gap-6 text-center text-xs">
        @foreach (['أعدّه', 'اعتمده'] as $role)
            <div><div class="mb-12 font-semibold">{{ $role }}</div><div class="border-t border-ink-400 pt-1 text-ink-500">التوقيع</div></div>
        @endforeach
    </div>
</section>
@endsection
