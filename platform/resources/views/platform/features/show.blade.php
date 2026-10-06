@extends('layouts.platform')
@section('title', $feature->label())

@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <div class="flex flex-wrap items-center gap-2">
            <h1 class="page-title">{{ $feature->label() }}</h1>
            @unless ($feature->included())
                <span class="chip chip-info">إضافة</span>
            @endunless
        </div>
        <p class="mt-1 max-w-3xl text-sm text-ink-500">{{ $feature->description() }}</p>
        <p class="mt-1 text-xs text-ink-400">
            {{ $feature->included()
                ? 'من أصل النظام: مفتوحةٌ لكل شركةٍ ما لم تُغلقها لها.'
                : 'مطفأةٌ في كل شركةٍ حتى تفتحها لها هنا.' }}
            الرسم الشهري يُضاف إلى فاتورة الشركة بالأيام التي عملت فيها، و<span class="num">0</span> مجّاناً.
        </p>
    </div>
    <a href="{{ route('admin.features.index') }}" class="btn-ghost">كل الميزات</a>
</div>

<div class="card divide-y divide-ink-100 px-5">
    @forelse ($companies as $row)
        @php $company = $row['company']; @endphp
        <form method="POST" action="{{ route('admin.companies.features.update', $company) }}"
              class="grid grid-cols-1 items-center gap-3 py-4 lg:grid-cols-[1fr_auto]">
            @csrf
            <input type="hidden" name="feature" value="{{ $feature->value }}">

            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="size-3.5 shrink-0 rounded" style="background: {{ $company->primary_color }}"></span>
                    <a href="{{ route('admin.companies.system', $company) }}#features" class="font-semibold text-ink-900 hover:underline">{{ $company->name }}</a>
                    {{-- حال الشركة إن لم تكن عاملة: «مفعّلة» بجانب «مغلقة» تُقرأ حالاً للميزة --}}
                    @unless ($company->status === 'active')
                        <x-company-status :status="$company->status" />
                    @endunless
                    @if ($row['enabled'])
                        <span class="chip chip-ok">مفتوحة{{ $row['price'] ? ' — '.number_format($row['price']).' د.ع شهرياً' : ' مجّاناً' }}</span>
                    @else
                        <span class="chip chip-mute">مغلقة</span>
                    @endif
                </div>
                @if ($row['decided'])
                    <p class="mt-1 text-xs text-ink-400">منذ <span class="num">{{ $row['since']->format('Y-m-d') }}</span></p>
                @endif
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <input type="hidden" name="enabled" value="0">
                <label class="inline-flex items-center gap-2 text-sm font-medium">
                    <input type="checkbox" name="enabled" value="1" @checked($row['enabled'])>
                    مفتوحة
                </label>
                <label class="sr-only" for="price-{{ $company->id }}">الرسم الشهري على {{ $company->name }}</label>
                <div class="relative w-44">
                    <input id="price-{{ $company->id }}" name="monthly_price" inputmode="numeric" class="field-input num ps-20 text-left" dir="ltr"
                           value="{{ $row['price'] ?: '' }}" placeholder="0" autocomplete="off" data-money>
                    <span class="pointer-events-none absolute inset-y-0 end-4 flex items-center text-xs text-ink-400">د.ع/شهر</span>
                </div>
                <button type="submit" class="btn-ghost">احفظ</button>
            </div>
        </form>
    @empty
        <p class="py-10 text-center text-sm text-ink-500">لا شركات بعد.</p>
    @endforelse
</div>
@endsection
