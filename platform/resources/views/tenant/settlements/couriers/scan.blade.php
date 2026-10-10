@extends('layouts.app')
@section('title', 'استلام كشف '.$courier->name)

@section('content')
{{-- مُسح رمز كشف المندوب (docs/plan/61): ما بيده الآن، وكشف استلامه بضغطة --}}
<div class="mx-auto max-w-lg">
    <section class="card space-y-4 p-6 text-center">
        <x-icon name="qr" class="mx-auto size-10 text-[var(--brand)]" />
        <h1 class="page-title">{{ $courier->name }}</h1>
        <p class="num text-sm text-ink-500">{{ $courier->code }} · {{ $courier->phone }}</p>
        <dl class="grid grid-cols-2 gap-3 text-sm">
            <div class="rounded-xl bg-ink-50 p-3">
                <dt class="text-ink-500">بيده الآن</dt>
                <dd class="num mt-1 text-xl font-bold">{{ number_format($courier->cash_in_hand) }} <span class="text-xs">د.ع</span></dd>
            </div>
            <div class="rounded-xl bg-ink-50 p-3">
                <dt class="text-ink-500">عمولته المعلّقة</dt>
                <dd class="num mt-1 text-xl font-bold text-ok-700">{{ number_format($courier->commission_balance) }} <span class="text-xs">د.ع</span></dd>
            </div>
        </dl>
        @can('money.settle')
            <form method="POST" action="{{ route('settlements.couriers.store') }}">
                @csrf
                <input type="hidden" name="courier_id" value="{{ $courier->id }}">
                <button type="submit" class="btn-primary w-full">افتح كشف استلامه</button>
            </form>
        @else
            <p class="text-sm text-ink-500">استلام الحساب لمن يملك «محاسبة المندوبين».</p>
        @endcan
    </section>
</div>
@endsection
