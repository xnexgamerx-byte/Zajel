@extends('layouts.app')
@section('title', 'المستخدمون')

@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-xl font-bold">مستخدمو الشركة</h1>
        <p class="mt-1 text-sm text-slate-500">
            حسابات المندوبين والتجّار تُدار من شاشاتها، لأن لكلٍّ سجلّاً تشغيلياً لا مجرّد حساب.
        </p>
    </div>
    <a href="{{ route('users.create') }}" class="btn-primary">+ مستخدم</a>
</div>

<form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
    <div class="min-w-64 flex-1">
        <label class="field-label" for="q">بحث</label>
        <input id="q" name="q" value="{{ request('q') }}" class="field-input" placeholder="الاسم أو الهاتف">
    </div>
    <button type="submit" class="btn-primary">تطبيق</button>
    <a href="{{ route('users.index') }}" class="btn-ghost">مسح</a>
</form>

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                <tr>
                    <th class="px-4 py-3 text-start font-semibold">الاسم</th>
                    <th class="px-4 py-3 text-start font-semibold">الهاتف</th>
                    <th class="px-4 py-3 text-start font-semibold">الدور</th>
                    <th class="px-4 py-3 text-start font-semibold">الفرع</th>
                    <th class="px-4 py-3 text-start font-semibold">آخر دخول</th>
                    <th class="px-4 py-3 text-start font-semibold">الحالة</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($users as $staff)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-semibold">{{ $staff->name }}</td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $staff->phone }}</td>
                        <td class="px-4 py-3">{{ $roles[$staff->role->value] ?? $staff->role->label() }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $staff->branch?->name ?? 'كل الفروع' }}</td>
                        <td class="px-4 py-3 text-xs text-slate-500" dir="ltr">
                            {{ $staff->last_login_at?->format('Y-m-d H:i') ?? '—' }}
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1
                                {{ $staff->is_active
                                    ? 'bg-emerald-50 text-emerald-700 ring-emerald-200'
                                    : 'bg-slate-100 text-slate-600 ring-slate-200' }}">
                                {{ $staff->is_active ? 'مفعّل' : 'موقوف' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-end">
                            <a href="{{ route('users.edit', $staff) }}"
                               class="text-sm font-semibold text-brand-700 hover:underline">تعديل</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-16 text-center text-slate-500">لا مستخدمين.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($users->hasPages())
        <div class="border-t border-slate-100 px-4 py-3">{{ $users->links() }}</div>
    @endif
</div>
@endsection
