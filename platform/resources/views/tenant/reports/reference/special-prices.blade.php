@extends('layouts.app')
@section('title', 'التجّار ذوو الأسعار الخاصّة')

@section('content')
@include('tenant.reports.reference._head', ['title' => 'التجّار ذوو الأسعار الخاصّة', 'blurb' => '«الزبائن ذوو الأسعار الخاصّة»: من على تسعيرةٍ غير الافتراضية، وأسعار التوصيل فيها.'])

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead><tr><th>التاجر</th><th>التسعيرة</th><th>الأسعار للتاجر</th></tr></thead>
            <tbody>
                @forelse ($merchants as $merchant)
                    <tr>
                        <td class="font-medium">{{ $merchant->business_name }} <span class="num text-xs text-ink-500">{{ $merchant->code }}</span></td>
                        <td>
                            @can('settings.pricing')
                                <a href="{{ route('pricing.edit', $merchant->price_list_id) }}" class="text-[var(--brand)] hover:underline">{{ $merchant->priceList?->name }}</a>
                            @else
                                {{ $merchant->priceList?->name }}
                            @endcan
                        </td>
                        <td class="text-sm">
                            @forelse (($merchant->priceList?->rules ?? collect())->sortBy(fn ($r) => $r->to_governorate_id ?? 0)->take(6) as $rule)
                                <span class="chip chip-mute me-1 mb-1">{{ $rule->toGovernorate?->name_ar ?? 'كل العراق' }}: <span class="num">{{ number_format($rule->delivery_fee) }}</span>@if ($rule->peripheral_fee !== null) / <span class="num">{{ number_format($rule->peripheral_fee) }}</span>@endif</span>
                            @empty
                                <span class="text-ink-500">لا أسعار في تسعيرته بعد</span>
                            @endforelse
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-12 text-center text-ink-500">كل التجّار على التسعيرة الافتراضية.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($merchants->hasPages())
        <div class="border-t border-ink-100 px-4 py-3">{{ $merchants->links() }}</div>
    @endif
</div>
@endsection
