@extends('layouts.app')
@section('title', 'تاريخ الموقف المالي')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="page-title">تاريخ الموقف المالي</h1>
        <p class="mt-1 text-sm text-ink-500">لقطاتٌ كما كانت ساعة التُقطت — بيد موظّفٍ، أو ليلياً آخر كل يوم.</p>
    </div>
    <a href="{{ route('money.position') }}" class="btn-ghost">الموقف الآن</a>
</div>

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th>تاريخ الالتقاط</th>
                    @foreach (\App\Services\Money\FinancialPosition::FIGURES as [$label])
                        <th>{{ $label }}</th>
                    @endforeach
                    <th>الإجماليّ</th>
                    <th>التقطها</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($snapshots as $snapshot)
                    <tr>
                        <td class="num text-xs whitespace-nowrap">{{ $snapshot->taken_at->format('Y-m-d H:i') }}</td>
                        @foreach (array_keys(\App\Services\Money\FinancialPosition::FIGURES) as $key)
                            <td class="num">{{ number_format((int) ($snapshot->figures[$key] ?? 0)) }}</td>
                        @endforeach
                        <td class="num font-bold {{ $snapshot->total >= 0 ? 'text-ok-700' : 'text-bad-700' }}">{{ number_format($snapshot->total) }}</td>
                        <td class="text-xs">{{ $snapshot->takenBy?->name ?? 'تلقائياً' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ count(\App\Services\Money\FinancialPosition::FIGURES) + 3 }}" class="px-4 py-16 text-center text-ink-500">لا لقطات بعد — احفظ نسخةً من الموقف الآن، أو انتظر لقطة الليلة.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($snapshots->hasPages())
        <div class="border-t border-ink-100 px-4 py-3">{{ $snapshots->links() }}</div>
    @endif
</div>
@endsection
