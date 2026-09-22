@extends('layouts.platform')
@section('title', 'الباقات')

@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-xl font-bold">الباقات</h1>
        <p class="mt-1 text-sm text-slate-500">
            ما تبيعه للشركات. تعديل باقة لا يمسّ اشتراكاً قائماً — أسعاره مجمَّدة.
        </p>
    </div>
    <a href="{{ route('admin.plans.create') }}" class="btn-primary">+ باقة جديدة</a>
</div>

<div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
    @foreach ($plans as $plan)
        <section class="card flex flex-col p-5 {{ $plan->is_active ? '' : 'opacity-60' }}">
            <div class="flex items-start justify-between gap-2">
                <div>
                    <h2 class="font-bold">{{ $plan->name }}</h2>
                    <div class="font-mono text-xs text-slate-400" dir="ltr">{{ $plan->code }}</div>
                </div>
                @unless ($plan->is_active)
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">معطّلة</span>
                @endunless
            </div>

            <div class="mt-4">
                <div class="text-2xl font-bold text-brand-700" dir="ltr">
                    {{ number_format($plan->price_monthly) }}
                    <span class="text-sm font-medium text-slate-500">د.ع/شهر</span>
                </div>
                @if ($plan->commission_per_shipment)
                    <div class="mt-1 text-sm text-slate-600" dir="ltr">
                        + {{ number_format($plan->commission_per_shipment) }} د.ع لكل شحنة
                    </div>
                @endif
            </div>

            <dl class="mt-4 space-y-1 border-t border-slate-100 pt-3 text-xs">
                @foreach ([
                    ['فروع', $plan->max_branches],
                    ['مندوبون', $plan->max_couriers],
                    ['تجّار', $plan->max_merchants],
                    ['شحنات/شهر', $plan->max_shipments_per_month],
                ] as [$label, $limit])
                    <div class="flex justify-between">
                        <dt class="text-slate-500">{{ $label }}</dt>
                        <dd class="font-medium">{{ $limit === null ? 'بلا حد' : number_format($limit) }}</dd>
                    </div>
                @endforeach
            </dl>

            <ul class="mt-3 space-y-1 border-t border-slate-100 pt-3 text-xs">
                @foreach ($features as $key => $label)
                    <li class="flex items-center gap-2 {{ $plan->has($key) ? 'text-slate-700' : 'text-slate-300 line-through' }}">
                        <span>{{ $plan->has($key) ? '✓' : '✕' }}</span> {{ $label }}
                    </li>
                @endforeach
            </ul>

            <div class="mt-4 flex items-center justify-between border-t border-slate-100 pt-3">
                <span class="text-xs text-slate-500">
                    {{ number_format($counts[$plan->id] ?? 0) }} شركة مشتركة
                </span>
                <a href="{{ route('admin.plans.edit', $plan) }}"
                   class="text-sm font-semibold text-brand-700 hover:underline">تعديل</a>
            </div>
        </section>
    @endforeach
</div>
@endsection
