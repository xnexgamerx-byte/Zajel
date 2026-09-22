@extends('layouts.app')
@section('title', 'الكيس ' . $bag->code)

@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <div class="flex items-center gap-3">
            <h1 class="num text-xl font-bold">{{ $bag->code }}</h1>
            <span class="chip {{ $bag->statusTone() }}">{{ $bag->statusLabel() }}</span>
        </div>
        <p class="mt-1 text-sm text-ink-500">
            {{ $bag->fromHub?->name }} ← {{ $bag->toHub?->name }}
            @if ($manifest)
                · على الكشف
                <a href="{{ route('manifests.show', $manifest) }}" class="num font-semibold hover:underline">{{ $manifest->code }}</a>
            @endif
        </p>
    </div>
    <a href="{{ route('bags.index') }}" class="btn-ghost">رجوع للأكياس</a>
</div>

@if (session('rejected'))
    <section class="card mb-5 border-warn-200 bg-warn-50 p-4">
        <h2 class="text-sm font-semibold text-warn-700">
            أرقام لم تدخل الكيس، عددها {{ count(session('rejected')) }}
        </h2>
        <ul class="mt-2 space-y-1 text-sm text-warn-700">
            @foreach (session('rejected') as $number => $reason)
                <li><span class="num font-semibold">{{ $number }}</span> — {{ $reason }}</li>
            @endforeach
        </ul>
    </section>
@endif

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <div class="lg:col-span-2">
        <section class="card overflow-hidden">
            <h2 class="card-title border-b border-ink-100 px-5 py-4">
                المحتوى — <span class="num">{{ number_format($shipments->count()) }}</span> شحنة
            </h2>
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th>الوصل</th>
                            <th>التاجر</th>
                            <th>الوجهة</th>
                            <th>المستلم</th>
                            <th>المبلغ</th>
                            @if ($bag->isOpen())
                                <th></th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($shipments as $shipment)
                            <tr>
                                <td>
                                    <a href="{{ route('shipments.show', $shipment) }}"
                                       class="num font-semibold hover:underline">{{ $shipment->number }}</a>
                                </td>
                                <td class="max-w-40 truncate text-ink-600">{{ $shipment->merchant?->business_name }}</td>
                                <td class="text-ink-600">{{ $shipment->governorate?->name_ar }}</td>
                                <td class="max-w-40 truncate text-ink-600">{{ $shipment->recipient_name }}</td>
                                <td class="num">{{ number_format($shipment->cod_amount) }}</td>
                                @if ($bag->isOpen())
                                    <td class="text-end">
                                        <form method="POST" action="{{ route('bags.remove', [$bag, $shipment]) }}">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn-ghost px-2 py-1 text-xs">أخرِج</button>
                                        </form>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $bag->isOpen() ? 6 : 5 }}" class="py-10 text-center text-ink-500">
                                    الكيس فارغ. امسح أول وصل.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div class="space-y-5">
        @if ($bag->isOpen())
            <section class="card p-5">
                <h2 class="card-title">امسح الوصولات</h2>
                <p class="card-hint mb-3">رقم في كل سطر. الماسح الضوئي يكتب ويُرسل بنفسه.</p>
                <form method="POST" action="{{ route('bags.add', $bag) }}" class="space-y-3">
                    @csrf
                    <textarea id="numbers" name="numbers" rows="6" required autofocus
                              class="field-input num font-mono"
                              placeholder="000123&#10;000124&#10;000125"></textarea>
                    @error('numbers') <p class="field-error">{{ $message }}</p> @enderror
                    <button type="submit" class="btn-primary w-full">أضف إلى الكيس</button>
                </form>
            </section>

            <section class="card p-5">
                <h2 class="card-title">ختم الكيس</h2>
                <p class="card-hint mb-3">بعد الختم لا يُضاف ولا يُخرَج شيء. الختم يسبق التحميل على الكشف.</p>
                <form method="POST" action="{{ route('bags.seal', $bag) }}">
                    @csrf
                    <button type="submit" class="btn-primary w-full" @disabled($shipments->isEmpty())>
                        اختم الكيس
                    </button>
                </form>
                @error('bag') <p class="field-error mt-2">{{ $message }}</p> @enderror
            </section>
        @elseif ($bag->status === 'received')
            <section class="card p-5">
                <h2 class="card-title">فتح الكيس</h2>
                <p class="card-hint mb-3">
                    عند الفتح تصير الشحنات في مخزن {{ $bag->toHub?->name }} وجاهزة للتوزيع من هنا.
                </p>
                <form method="POST" action="{{ route('bags.open', $bag) }}">
                    @csrf
                    <button type="submit" class="btn-primary w-full">افتح الكيس</button>
                </form>
            </section>
        @endif

        <section class="card p-5">
            <h2 class="card-title">سجلّ الكيس</h2>
            <dl class="mt-3 space-y-2 text-sm">
                @foreach ([
                    ['أُنشئ', $bag->created_at],
                    ['خُتم', $bag->sealed_at],
                    ['وصل', $bag->received_at],
                    ['فُتح', $bag->opened_at],
                ] as [$label, $at])
                    <div class="flex justify-between">
                        <dt class="text-ink-600">{{ $label }}</dt>
                        <dd class="{{ $at ? 'text-ink-800' : 'text-ink-400' }}">
                            {{ $at?->format('Y-m-d H:i') ?? '—' }}
                        </dd>
                    </div>
                @endforeach
            </dl>
            @if ($bag->notes)
                <p class="mt-3 rounded-lg bg-ink-50 px-3 py-2 text-xs text-ink-600">{{ $bag->notes }}</p>
            @endif
        </section>
    </div>
</div>
@endsection
