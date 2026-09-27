@extends('layouts.app')
@section('title', 'الموقف المالي')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="page-title">الموقف المالي</h1>
        <p class="mt-1 text-sm text-ink-500">
            ما عندنا وما لنا وما علينا — الآن. كل رقمٍ من مصدره الذي يُحاسَب به.
            @if ($last) آخر لقطة: <a href="{{ route('money.position.history') }}" class="text-[var(--brand)] hover:underline">{{ $last->taken_at->format('Y-m-d H:i') }}</a>. @endif
        </p>
    </div>
    @can('money.cash')
        <form method="POST" action="{{ route('money.position.store') }}">
            @csrf
            <button class="btn-primary">حفظ نسخة من الموقف المالي الحالي</button>
        </form>
    @endcan
</div>

<div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
    @foreach (\App\Services\Money\FinancialPosition::FIGURES as $key => [$label, $side])
        <div class="card p-4">
            <div class="text-xs font-medium text-ink-500">{{ $label }}</div>
            <div class="mt-1 text-2xl font-bold {{ $side === 'liability' ? 'text-bad-700' : 'text-ok-700' }}">
                <span class="num">{{ number_format($position['figures'][$key]) }}</span> <span class="text-sm font-medium text-ink-500">د.ع</span>
            </div>
            <div class="mt-1 text-xs text-ink-500">{{ $side === 'liability' ? 'علينا' : 'لنا' }}</div>
        </div>
    @endforeach
</div>

<div class="card mb-5 flex flex-wrap items-center justify-between gap-3 p-5">
    <div>
        <div class="text-sm font-bold">إجمالي الموقف</div>
        <div class="text-xs text-ink-500">ما لنا ناقص ما علينا. ديون الفروع فيما بينها تتقاصّ على مستوى الشركة فلا تُجمع هنا.</div>
    </div>
    <div class="text-3xl font-black {{ $position['total'] >= 0 ? 'text-ok-700' : 'text-bad-700' }}"><span class="num">{{ number_format($position['total']) }}</span> <span class="text-base font-medium text-ink-500">د.ع</span></div>
</div>

@if ($position['payables_by_pickup'])
    <section class="card overflow-hidden">
        <h2 class="border-b border-ink-100 px-5 py-4 text-sm font-bold">دفوعات مستحقّة للتجّار — مقسّمة حسب مندوب الاستلام</h2>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead><tr><th>مندوب الاستلام</th><th>المستحقّ لتجّاره</th></tr></thead>
                <tbody>
                    @foreach ($position['payables_by_pickup'] as $name => $total)
                        <tr><td>{{ $name }}</td><td class="num font-semibold">{{ number_format($total) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif
@endsection
