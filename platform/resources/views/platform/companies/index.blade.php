@extends('layouts.platform')
@section('title', 'الشركات')

@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-xl font-bold">الشركات المشتركة</h1>
        <p class="mt-1 text-sm text-slate-500">لكل شركة نظامها المعزول على نطاقها الفرعي.</p>
    </div>
    <a href="{{ route('admin.companies.create') }}" class="btn-primary">+ تسجيل شركة</a>
</div>

<form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
    <div class="min-w-64 flex-1">
        <label class="field-label" for="q">بحث</label>
        <input id="q" name="q" value="{{ request('q') }}" class="field-input" placeholder="الاسم أو النطاق">
    </div>
    <div>
        <label class="field-label" for="status">الحالة</label>
        <select id="status" name="status" class="field-input">
            <option value="">الكل</option>
            @foreach (['active' => 'مفعّلة', 'trial' => 'تجريبية', 'suspended' => 'موقوفة', 'cancelled' => 'ملغاة'] as $v => $l)
                <option value="{{ $v }}" @selected(request('status') === $v)>{{ $l }}</option>
            @endforeach
        </select>
    </div>
    <button type="submit" class="btn-primary">تطبيق</button>
    <a href="{{ route('admin.companies.index') }}" class="btn-ghost">مسح</a>
</form>

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                <tr>
                    <th class="px-4 py-3 text-start font-semibold">الشركة</th>
                    <th class="px-4 py-3 text-start font-semibold">النطاق</th>
                    <th class="px-4 py-3 text-start font-semibold">الباقة</th>
                    <th class="px-4 py-3 text-start font-semibold">شحنات الشهر</th>
                    <th class="px-4 py-3 text-start font-semibold">الإجمالي</th>
                    <th class="px-4 py-3 text-start font-semibold">مستخدمون</th>
                    <th class="px-4 py-3 text-start font-semibold">الحالة</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($companies as $company)
                    @php
                        $counts = $shipmentCounts[$company->id] ?? null;
                        $subscription = $subscriptions[$company->id] ?? null;
                    @endphp
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <span class="h-3 w-3 shrink-0 rounded-full"
                                      style="background: {{ $company->primary_color }}"></span>
                                <a href="{{ route('admin.companies.show', $company) }}"
                                   class="font-semibold text-brand-700 hover:underline">{{ $company->name }}</a>
                            </div>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-slate-500" dir="ltr">
                            {{ $company->slug }}.{{ config('zajel.tenant_domain') }}
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ $subscription?->plan->name ?? '—' }}</td>
                        <td class="px-4 py-3 font-semibold" dir="ltr">
                            {{ number_format((int) ($counts->this_month ?? 0)) }}
                        </td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">
                            {{ number_format((int) ($counts->total ?? 0)) }}
                        </td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $company->users_count }}</td>
                        <td class="px-4 py-3"><x-company-status :status="$company->status" /></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-16 text-center">
                            <div class="text-slate-500">لا شركات بعد.</div>
                            <a href="{{ route('admin.companies.create') }}" class="btn-primary mt-4">سجّل أول شركة</a>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($companies->hasPages())
        <div class="border-t border-slate-100 px-4 py-3">{{ $companies->links() }}</div>
    @endif
</div>
@endsection
