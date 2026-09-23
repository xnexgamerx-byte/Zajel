@extends('layouts.app')
@section('title', 'الكشف ' . $manifest->code)

@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <div class="flex items-center gap-3">
            <h1 class="page-title num">{{ $manifest->code }}</h1>
            <span class="chip {{ $manifest->statusTone() }}">{{ $manifest->statusLabel() }}</span>
        </div>
        <p class="mt-1 text-sm text-ink-500">
            {{ $manifest->fromHub?->name }} ← {{ $manifest->toHub?->name }}
            @if ($manifest->driver_name)
                · {{ $manifest->driver_name }}
                @if ($manifest->driver_phone)<span class="num">{{ $manifest->driver_phone }}</span>@endif
            @endif
        </p>
    </div>
    <div class="flex gap-2">
        <a href="{{ route('manifests.print', $manifest) }}" class="btn-ghost">اطبع الكشف</a>
        <a href="{{ route('manifests.index') }}" class="btn-ghost">رجوع للكشوف</a>
    </div>
</div>

@if ($manifest->missingBags())
    <div class="card mb-5 border-bad-200 bg-bad-50 p-4 text-sm text-bad-700">
        أكياس لم تصل مع هذا الكشف، عددها <span class="num font-bold">{{ $manifest->missingBags() }}</span>.
        سُجّل على كل شحنة فيها أنها مفقودة في الطريق.
    </div>
@endif

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <div class="lg:col-span-2">
        <section class="card overflow-hidden">
            <h2 class="card-title border-b border-ink-100 px-5 py-4">
                الحمولة — <span class="num">{{ number_format($manifest->bags_count) }}</span> كيساً،
                <span class="num">{{ number_format($manifest->shipments_count) }}</span> شحنة
            </h2>
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th>الكيس</th>
                            <th>الشحنات</th>
                            <th>الحالة</th>
                            <th>الوصول</th>
                            @if ($manifest->isDraft())
                                <th></th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($manifest->bags as $bag)
                            <tr class="{{ $bag->pivot->is_missing ? 'bg-bad-50' : '' }}">
                                <td>
                                    <a href="{{ route('bags.show', $bag) }}"
                                       class="num font-semibold hover:underline">{{ $bag->code }}</a>
                                </td>
                                <td class="num">{{ number_format($bag->shipments_count) }}</td>
                                <td><span class="chip {{ $bag->statusTone() }}">{{ $bag->statusLabel() }}</span></td>
                                <td>
                                    @if ($bag->pivot->is_missing)
                                        <span class="chip chip-bad">مفقود</span>
                                    @elseif ($bag->pivot->unloaded_at)
                                        <span class="text-sm text-ink-500">
                                            {{ \Illuminate\Support\Carbon::parse($bag->pivot->unloaded_at)->format('Y-m-d H:i') }}
                                        </span>
                                    @else
                                        <span class="text-ink-400">—</span>
                                    @endif
                                </td>
                                @if ($manifest->isDraft())
                                    <td class="text-end">
                                        <form method="POST" action="{{ route('manifests.unload', [$manifest, $bag]) }}">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn-ghost px-2 py-1 text-xs">أنزِل</button>
                                        </form>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $manifest->isDraft() ? 5 : 4 }}" class="py-10 text-center text-ink-500">
                                    لا أكياس على هذا الكشف بعد.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div class="space-y-5">
        @if ($manifest->isDraft())
            <section class="card p-5">
                <h2 class="card-title">حمّل كيساً</h2>
                <p class="card-hint mb-3">الأكياس المختومة على هذا المسار والتي لم تُحمَّل بعد.</p>

                @if ($available->isEmpty())
                    <p class="rounded-lg bg-ink-50 px-3 py-2 text-sm text-ink-600">
                        لا كيس مختوم جاهز على مسار {{ $manifest->fromHub?->name }} ← {{ $manifest->toHub?->name }}.
                    </p>
                @else
                    <form method="POST" action="{{ route('manifests.load', $manifest) }}" class="space-y-3">
                        @csrf
                        <select name="bag_id" class="field-input" required>
                            @foreach ($available as $bag)
                                <option value="{{ $bag->id }}">
                                    {{ $bag->code }} ({{ \App\Support\Arabic::shipments((int) $bag->shipments_count) }})
                                </option>
                            @endforeach
                        </select>
                        @error('bag_id') <p class="field-error">{{ $message }}</p> @enderror
                        <button type="submit" class="btn-ghost w-full">حمّل</button>
                    </form>
                @endif
            </section>

            <section class="card p-5">
                <h2 class="card-title">إرسال الكشف</h2>
                <p class="card-hint mb-3">عند الإرسال تصير كل شحنات الحمولة «قيد النقل».</p>
                <form method="POST" action="{{ route('manifests.dispatch', $manifest) }}">
                    @csrf
                    <button type="submit" class="btn-primary w-full" @disabled($manifest->bags_count === 0)>
                        أرسل الكشف
                    </button>
                </form>
                @error('manifest') <p class="field-error mt-2">{{ $message }}</p> @enderror
            </section>
        @endif

        <section class="card p-5">
            <h2 class="card-title">سجلّ الكشف</h2>
            <dl class="mt-3 space-y-2 text-sm">
                @foreach ([['أُنشئ', $manifest->created_at], ['غادر', $manifest->departed_at],
                           ['وصل', $manifest->arrived_at]] as [$label, $at])
                    <div class="flex justify-between">
                        <dt class="text-ink-600">{{ $label }}</dt>
                        <dd class="{{ $at ? 'text-ink-800' : 'text-ink-400' }}">
                            {{ $at?->format('Y-m-d H:i') ?? '—' }}
                        </dd>
                    </div>
                @endforeach
            </dl>
            @if ($manifest->notes)
                <p class="mt-3 rounded-lg bg-ink-50 px-3 py-2 text-xs text-ink-600">{{ $manifest->notes }}</p>
            @endif
        </section>
    </div>
</div>
@endsection
