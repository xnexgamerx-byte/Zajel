@extends('layouts.app')
@section('title', 'الفروع')

@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-xl font-bold">الفروع</h1>
        <p class="mt-1 text-sm text-slate-500">
            كل شحنة تتبع فرعاً، والمستخدم المقيّد بفرع لا يرى غيره.
        </p>
    </div>
    <a href="{{ route('branches.create') }}" class="btn-primary">+ فرع</a>
</div>

<div class="card overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-slate-50 text-xs uppercase text-slate-500">
            <tr>
                <th class="px-4 py-3 text-start font-semibold">الرمز</th>
                <th class="px-4 py-3 text-start font-semibold">الاسم</th>
                <th class="px-4 py-3 text-start font-semibold">المحافظة</th>
                <th class="px-4 py-3 text-start font-semibold">الهاتف</th>
                <th class="px-4 py-3 text-start font-semibold">مستخدمون</th>
                <th class="px-4 py-3 text-start font-semibold">الحالة</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($branches as $branch)
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3 font-mono text-slate-500" dir="ltr">{{ $branch->code }}</td>
                    <td class="px-4 py-3 font-semibold">
                        {{ $branch->name }}
                        @if ($branch->is_main)
                            <span class="ms-1 rounded bg-brand-50 px-1.5 py-0.5 text-xs font-semibold text-brand-700">
                                رئيسي
                            </span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-slate-600">{{ $branch->governorate?->name_ar ?? '—' }}</td>
                    <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $branch->phone ?? '—' }}</td>
                    <td class="px-4 py-3" dir="ltr">{{ number_format($branch->users_count) }}</td>
                    <td class="px-4 py-3">
                        <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1
                            {{ $branch->is_active
                                ? 'bg-emerald-50 text-emerald-700 ring-emerald-200'
                                : 'bg-slate-100 text-slate-600 ring-slate-200' }}">
                            {{ $branch->is_active ? 'مفعّل' : 'موقوف' }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-end">
                        <a href="{{ route('branches.edit', $branch) }}"
                           class="text-sm font-semibold text-brand-700 hover:underline">تعديل</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-16 text-center text-slate-500">لا فروع.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
