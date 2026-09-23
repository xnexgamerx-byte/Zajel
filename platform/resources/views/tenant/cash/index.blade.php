@extends('layouts.app')
@section('title', 'القاصة')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="page-title">القاصة</h1>
        <p class="mt-1 text-sm text-ink-500">
            ما بقي في الدرج بعد تسليم المندوبين ودفع التجّار والمصروفات.
        </p>
    </div>
    <div class="card px-5 py-3 text-center">
        <div class="text-xs text-ink-500">مجموع الصناديق المفعّلة</div>
        <div class="num mt-0.5 text-2xl font-bold text-[var(--brand)]">{{ number_format($total) }}</div>
    </div>
</div>

<div class="mb-4 flex flex-wrap gap-2">
    @foreach ($boxes as $item)
        <a href="{{ route('cash.index', ['box_id' => $item->id]) }}"
           class="card px-4 py-3 transition hover:border-brand {{ $box?->id === $item->id ? 'border-brand ring-1 ring-brand' : '' }}">
            <div class="text-sm font-semibold">{{ $item->name }}</div>
            <div class="text-xs text-ink-500">
                {{ $item->typeLabel() }}{{ $item->branch ? ' · '.$item->branch->name : '' }}
            </div>
            <div class="num mt-1 text-lg font-bold {{ $item->balance < 0 ? 'text-bad-700' : 'text-ink-900' }}">
                {{ number_format($item->balance) }}
            </div>
        </a>
    @endforeach
</div>

@if (! $box)
    <section class="card p-10 text-center">
        <p class="text-ink-500">لا صندوق بعد. أنشئ القاصة الرئيسية لتبدأ حركة النقد.</p>
    </section>
@else
    <div class="space-y-5">
        <div>
            <div class="mb-4 grid grid-cols-3 gap-3">
                <div class="stat">
                    <div class="stat-label">دخل اليوم</div>
                    <div class="num mt-1 text-2xl font-bold text-ok-700">{{ number_format($today['in']) }}</div>
                </div>
                <div class="stat">
                    <div class="stat-label">خرج اليوم</div>
                    <div class="num mt-1 text-2xl font-bold text-bad-700">{{ number_format($today['out']) }}</div>
                </div>
                <div class="stat">
                    <div class="stat-label">الرصيد الآن</div>
                    <div class="num mt-1 text-2xl font-bold">{{ number_format($box->balance) }}</div>
                </div>
            </div>

            @unless ($check['matches'])
                {{-- الرصيد المخزَّن خالف مجموع الحركات: خلل يجب أن يُرى لا أن يُخفى --}}
                <div class="card mb-4 border-bad-200 bg-bad-50 p-4 text-sm text-bad-700">
                    رصيد الصندوق لا يطابق مجموع حركاته: الدفتر
                    <span class="num font-bold">{{ number_format($check['ledger']) }}</span>
                    والمخزَّن <span class="num font-bold">{{ number_format($check['stored']) }}</span>.
                    راجع الحركات قبل أي جرد.
                </div>
            @endunless

            <section class="card overflow-hidden">
                <h2 class="card-title border-b border-ink-100 px-5 py-4">حركات {{ $box->name }}</h2>
                <div class="overflow-x-auto">
                    <table class="tbl">
                        <thead>
                            <tr>
                                <th>التاريخ</th>
                                <th>الحركة</th>
                                <th>البيان</th>
                                <th>داخل</th>
                                <th>خارج</th>
                                <th>الرصيد بعدها</th>
                                <th>بواسطة</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($movements as $movement)
                                <tr>
                                    <td class="whitespace-nowrap text-sm text-ink-500">
                                        {{ $movement->created_at->format('Y-m-d H:i') }}
                                    </td>
                                    <td>
                                        <span class="chip {{ $movement->direction === 'in' ? 'chip-ok' : 'chip-warn' }}">
                                            {{ $movement->categoryLabel() }}
                                        </span>
                                    </td>
                                    <td class="max-w-80 truncate text-ink-600">{{ $movement->description }}</td>
                                    <td class="num text-ok-700">
                                        {{ $movement->direction === 'in' ? number_format($movement->amount) : '—' }}
                                    </td>
                                    <td class="num text-bad-700">
                                        {{ $movement->direction === 'out' ? number_format($movement->amount) : '—' }}
                                    </td>
                                    <td class="num font-semibold">{{ number_format($movement->balance_after) }}</td>
                                    <td class="text-sm text-ink-500">{{ $movement->user?->name ?? 'النظام' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="py-10 text-center text-ink-500">لا حركة في هذا الصندوق بعد.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($movements->hasPages())
                    <div class="border-t border-ink-100 px-5 py-4">{{ $movements->links() }}</div>
                @endif
            </section>
        </div>

        <div class="grid grid-cols-1 gap-5 md:grid-cols-2 lg:grid-cols-3">
            <section class="card h-fit p-5">
                <h2 class="card-title">جرد الصندوق</h2>
                <p class="card-hint mb-4">عدّ ما في الدرج وأدخله. الفرق يُقيَّد بسببه لا يُكتب فوق الرصيد.</p>
                <form method="POST" action="{{ route('cash.adjust', $box) }}" class="space-y-3">
                    @csrf
                    <div>
                        <label class="field-label" for="counted">المبلغ المعدود</label>
                        <input id="counted" name="counted" type="number" min="0" step="1" required
                               class="field-input num" value="{{ old('counted', $box->balance) }}">
                        @error('counted') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="field-label" for="reason">السبب</label>
                        <input id="reason" name="reason" type="text" maxlength="255" required class="field-input"
                               placeholder="مثلاً: نقص عند جرد المساء">
                        @error('reason') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="btn-ghost w-full">قيّد الفرق</button>
                </form>
            </section>

            @if ($boxes->where('is_active', true)->count() > 1)
                @php
                    $live = $boxes->where('is_active', true);
                    // الوجهة تبدأ على صندوق آخر: المناقلة إلى الصندوق نفسه ليست مناقلة
                    $target = $live->firstWhere('id', '!=', $box->id);
                @endphp
                <section class="card h-fit p-5">
                    <h2 class="card-title">مناقلة بين صندوقين</h2>
                    <form method="POST" action="{{ route('cash.transfer') }}" class="mt-3 space-y-3">
                        @csrf
                        <div>
                            <label class="field-label" for="from_box_id">من</label>
                            <select id="from_box_id" name="from_box_id" class="field-input" required>
                                @foreach ($live as $item)
                                    <option value="{{ $item->id }}" @selected($box->id === $item->id)>
                                        {{ $item->name }} ({{ number_format($item->balance) }})
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="field-label" for="to_box_id">إلى</label>
                            <select id="to_box_id" name="to_box_id" class="field-input" required>
                                @foreach ($live as $item)
                                    <option value="{{ $item->id }}" @selected($target?->id === $item->id)>
                                        {{ $item->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('to_box_id') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="field-label" for="transfer_amount">المبلغ</label>
                            <input id="transfer_amount" name="amount" type="number" min="1" step="1" required
                                   class="field-input num">
                            @error('amount') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <button type="submit" class="btn-ghost w-full">نفّذ المناقلة</button>
                    </form>
                </section>
            @endif

            <section class="card h-fit p-5">
                <h2 class="card-title">صندوق جديد</h2>
                <form method="POST" action="{{ route('cash.store') }}" class="mt-3 space-y-3">
                    @csrf
                    <div>
                        <label class="field-label" for="name">الاسم</label>
                        <input id="name" name="name" type="text" maxlength="120" required class="field-input"
                               value="{{ old('name') }}" placeholder="صندوق فرع البصرة">
                        @error('name') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="field-label" for="code">الرمز</label>
                            <input id="code" name="code" type="text" maxlength="20" required class="field-input"
                                   value="{{ old('code') }}" placeholder="BSR">
                            @error('code') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="field-label" for="type">النوع</label>
                            <select id="type" name="type" class="field-input" required>
                                <option value="branch">صندوق فرع</option>
                                <option value="petty">صندوق نثريّة</option>
                                <option value="main">القاصة الرئيسية</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="field-label" for="branch_id">الفرع</label>
                        <select id="branch_id" name="branch_id" class="field-input">
                            <option value="">بلا فرع</option>
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="field-label" for="opening">رصيد افتتاحي</label>
                        <input id="opening" name="opening" type="number" min="0" step="1" class="field-input num"
                               value="0">
                        <p class="field-hint">يُسجَّل حركةً لا قيمةً ابتدائية، فيظهر في الجرد.</p>
                    </div>
                    <button type="submit" class="btn-ghost w-full">أنشئ الصندوق</button>
                </form>
            </section>
        </div>
    </div>
@endif
@endsection
