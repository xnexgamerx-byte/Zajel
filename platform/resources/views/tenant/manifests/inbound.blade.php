@extends('layouts.app')
@section('title', 'وارد المراكز')

@section('content')
<div class="mb-5">
    <h1 class="page-title">وارد المراكز</h1>
    <p class="mt-1 text-sm text-ink-500">
        كشوف في الطريق إليك. حدّد الأكياس التي وصلت فعلاً — وما لم تحدّده يُسجَّل مفقوداً لا منسيّاً.
    </p>
</div>

@forelse ($manifests as $manifest)
    <section class="card mb-5 overflow-hidden">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-ink-100 px-5 py-4">
            <div>
                <a href="{{ route('manifests.show', $manifest) }}"
                   class="num text-lg font-bold hover:underline">{{ $manifest->code }}</a>
                <p class="mt-0.5 text-sm text-ink-500">
                    {{ $manifest->fromHub?->name }} ← {{ $manifest->toHub?->name }}
                    @if ($manifest->driver_name) · {{ $manifest->driver_name }} @endif
                    @if ($manifest->departed_at)
                        · غادر {{ $manifest->departed_at->format('Y-m-d H:i') }}
                    @endif
                </p>
            </div>
            <div class="text-end">
                <div class="num text-lg font-bold">{{ number_format($manifest->bags_count) }}</div>
                <div class="text-xs text-ink-500">كيساً محمَّلاً</div>
            </div>
        </div>

        <form method="POST" action="{{ route('manifests.receive', $manifest) }}">
            @csrf
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th class="w-10">
                                <input type="checkbox" aria-label="تحديد الكل" checked
                                       class="size-4 accent-[var(--brand)]"
                                       onchange="this.closest('table').querySelectorAll('tbody input[type=checkbox]')
                                                 .forEach(c => c.checked = this.checked)">
                            </th>
                            <th>الكيس</th>
                            <th>الشحنات</th>
                            <th>خُتم</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($manifest->bags as $bag)
                            <tr>
                                <td>
                                    {{-- محدَّد افتراضياً: الأصل أن تصل الأكياس، والاستثناء يُرفَع باليد --}}
                                    <input type="checkbox" name="bag_ids[]" value="{{ $bag->id }}" checked
                                           aria-label="الكيس {{ $bag->code }}" class="size-4 accent-[var(--brand)]">
                                </td>
                                <td class="num font-semibold">{{ $bag->code }}</td>
                                <td class="num">{{ number_format($bag->shipments_count) }}</td>
                                <td class="text-sm text-ink-500">{{ $bag->sealed_at?->format('Y-m-d H:i') ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex flex-wrap items-center gap-3 border-t border-ink-100 p-5">
                <input type="text" name="notes" maxlength="500" class="field-input w-auto min-w-64 flex-1"
                       placeholder="ملاحظة على الاستلام (اختياري)">
                <button type="submit" class="btn-primary">استلم الكشف</button>
                <p class="ms-auto text-xs text-ink-500">
                    الكيس غير المحدَّد يُسجَّل مفقوداً على كل شحنة فيه.
                </p>
            </div>
        </form>
    </section>
@empty
    <section class="card p-10 text-center">
        <p class="text-ink-500">لا كشوف في الطريق الآن.</p>
    </section>
@endforelse
@endsection
