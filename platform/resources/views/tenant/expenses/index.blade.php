@extends('layouts.app')
@section('title', 'المصروفات')

@section('content')
<div class="mb-5">
    <h1 class="page-title">المصروفات</h1>
    <p class="mt-1 text-sm text-ink-500">ماذا علينا هذه الفترة، وأين ذهب المال.</p>
</div>

<div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
    <div class="stat">
        <div class="stat-label">مجموع الفترة</div>
        <div class="num mt-1 text-2xl font-bold">{{ number_format($total) }}</div>
    </div>
    <div class="stat">
        <div class="stat-label">غير مدفوع</div>
        <div class="num mt-1 text-2xl font-bold {{ $unpaid ? 'text-warn-700' : 'text-ink-400' }}">
            {{ number_format($unpaid) }}
        </div>
    </div>
    <div class="stat">
        <div class="stat-label">مدفوع</div>
        <div class="num mt-1 text-2xl font-bold text-ok-700">{{ number_format($total - $unpaid) }}</div>
    </div>
    <div class="stat">
        <div class="stat-label">أكبر باب</div>
        <div class="mt-1 truncate text-lg font-bold">{{ $byCategory->first()->name ?? '—' }}</div>
        <div class="num text-xs text-ink-500">{{ number_format($byCategory->first()->total ?? 0) }}</div>
    </div>
</div>

<form method="GET" class="card mb-5 flex flex-wrap items-end gap-3 p-4">
    <div>
        <label class="field-label" for="from">من</label>
        <input id="from" name="from" type="date" class="field-input" value="{{ $from->toDateString() }}">
    </div>
    <div>
        <label class="field-label" for="to">إلى</label>
        <input id="to" name="to" type="date" class="field-input" value="{{ $to->toDateString() }}">
    </div>
    <div>
        <label class="field-label" for="category_id">الباب</label>
        <select id="category_id" name="category_id" class="field-input">
            <option value="">كل الأبواب</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}" @selected(request()->integer('category_id') === $category->id)>
                    {{ $category->name_ar }}
                </option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="field-label" for="status">الحالة</label>
        <select id="status" name="status" class="field-input">
            <option value="">الكل</option>
            @foreach (['recorded' => 'مسجَّل', 'paid' => 'مدفوع', 'cancelled' => 'ملغى'] as $v => $l)
                <option value="{{ $v }}" @selected(request('status') === $v)>{{ $l }}</option>
            @endforeach
        </select>
    </div>
    <button type="submit" class="btn-primary">تطبيق</button>
    <a href="{{ route('expenses.index') }}" class="btn-ghost">هذا الشهر</a>
</form>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <div class="lg:col-span-2">
        <section class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th>الرقم</th>
                            <th>التاريخ</th>
                            <th>البيان</th>
                            <th>المبلغ</th>
                            <th>الحالة</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($expenses as $expense)
                            <tr>
                                <td class="num font-semibold">{{ $expense->number }}</td>
                                <td class="whitespace-nowrap text-sm text-ink-500">
                                    {{ $expense->spent_on->format('Y-m-d') }}
                                </td>
                                {{-- الباب سطر ثانٍ تحت البيان لا عموداً: عمود سابع يدفع
                                     زرّ الدفع خارج الشاشة فلا يجده أحد. --}}
                                <td class="max-w-72">
                                    <div class="truncate">{{ $expense->description }}</div>
                                    <div class="truncate text-xs text-ink-500">
                                        {{ $expense->category?->name_ar }}{{ $expense->payee ? ' · '.$expense->payee : '' }}
                                    </div>
                                </td>
                                <td class="num font-semibold">{{ number_format($expense->amount) }}</td>
                                <td>
                                    <span class="chip {{ match ($expense->status) {
                                        'paid' => 'chip-ok', 'cancelled' => 'chip-mute', default => 'chip-warn',
                                    } }}">{{ $expense->statusLabel() }}</span>
                                </td>
                                <td class="text-end">
                                    @if ($expense->status === 'recorded' && $boxes->isNotEmpty())
                                        {{-- زرّ واحد ينادي نافذة واحدة مشتركة: قائمة صناديق في كل
                                             صفّ تُوسّع الجدول حتى يُقصّ عموده الأخير. --}}
                                        <button type="button" class="btn-ghost px-3 py-1 text-xs"
                                                onclick="payExpense(this)"
                                                data-url="{{ route('expenses.pay', $expense) }}"
                                                data-number="{{ $expense->number }}"
                                                data-amount="{{ number_format($expense->amount) }}"
                                                data-description="{{ $expense->description }}">ادفع</button>
                                    @elseif ($expense->status === 'paid')
                                        <span class="text-xs text-ink-500">{{ $expense->cashBox?->name }}</span>
                                    @elseif ($expense->status === 'cancelled')
                                        <span class="text-xs text-ink-400">{{ $expense->cancel_reason }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-10 text-center text-ink-500">لا مصروف في هذه الفترة.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($expenses->hasPages())
                <div class="border-t border-ink-100 px-5 py-4">{{ $expenses->links() }}</div>
            @endif
        </section>

        @if ($boxes->isNotEmpty())
            <dialog id="pay-expense" class="modal">
                <form method="POST" id="pay-expense-form">
                    @csrf
                    <div class="modal-head">
                        <h3 class="font-bold">دفع المصروف <span data-pay-number class="num"></span></h3>
                        <p class="mt-1 text-xs text-ink-500" data-pay-description></p>
                    </div>

                    <div class="modal-body">
                        <div class="flex justify-between rounded-lg bg-ink-50 px-3 py-2 text-sm">
                            <span class="text-ink-600">المبلغ</span>
                            <span class="num font-semibold" data-pay-amount></span>
                        </div>

                        <div>
                            <label class="field-label" for="pay_from_box">يُدفع من</label>
                            <select id="pay_from_box" name="cash_box_id" class="field-input" required>
                                @foreach ($boxes as $item)
                                    <option value="{{ $item->id }}">
                                        {{ $item->name }} ({{ number_format($item->balance) }})
                                    </option>
                                @endforeach
                            </select>
                            @error('cash_box_id') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="modal-foot">
                        <button type="button" class="btn-ghost"
                                onclick="document.getElementById('pay-expense').close()">تراجع</button>
                        <button type="submit" class="btn-primary">ادفع الآن</button>
                    </div>
                </form>
            </dialog>

            <script>
                function payExpense(button) {
                    const dialog = document.getElementById('pay-expense');
                    document.getElementById('pay-expense-form').action = button.dataset.url;
                    dialog.querySelector('[data-pay-number]').textContent = button.dataset.number;
                    dialog.querySelector('[data-pay-amount]').textContent = button.dataset.amount;
                    dialog.querySelector('[data-pay-description]').textContent = button.dataset.description;
                    dialog.showModal();
                }
            </script>
        @endif

        @if ($byCategory->isNotEmpty())
            <section class="card mt-5 p-5">
                <h2 class="card-title">أين ذهب المال</h2>
                <p class="card-hint mb-4">مجموع كل باب في الفترة المختارة.</p>
                @php $max = $byCategory->max('total') ?: 1; @endphp
                <div class="space-y-2">
                    @foreach ($byCategory as $row)
                        <div>
                            <div class="mb-1 flex justify-between text-sm">
                                <span class="text-ink-700">{{ $row->name }}</span>
                                <span class="num font-semibold">{{ number_format($row->total) }}</span>
                            </div>
                            {{-- الشريط تعزيز للرقم لا بديل عنه --}}
                            <div class="h-2 overflow-hidden rounded-full bg-ink-100">
                                <div class="h-full rounded-full bg-[var(--brand)]"
                                     style="width: {{ round($row->total / $max * 100) }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </div>

    <section class="card h-fit p-5">
        <h2 class="card-title">مصروف جديد</h2>
        <form method="POST" action="{{ route('expenses.store') }}" class="mt-3 space-y-3">
            @csrf
            <div>
                <label class="field-label" for="expense_category_id">الباب <span class="text-bad-700">*</span></label>
                <select id="expense_category_id" name="expense_category_id" class="field-input" required>
                    <option value="">اختر الباب</option>
                    @foreach ($categories->groupBy('group') as $group => $items)
                        <optgroup label="{{ $items->first()->groupLabel() }}">
                            @foreach ($items as $category)
                                <option value="{{ $category->id }}"
                                        @selected((int) old('expense_category_id') === $category->id)>
                                    {{ $category->name_ar }}
                                </option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                @error('expense_category_id') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="field-label" for="amount">المبلغ <span class="text-bad-700">*</span></label>
                    <input id="amount" name="amount" type="number" min="1" step="1" required
                           class="field-input num" value="{{ old('amount') }}">
                    @error('amount') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="field-label" for="spent_on">التاريخ <span class="text-bad-700">*</span></label>
                    <input id="spent_on" name="spent_on" type="date" required class="field-input"
                           max="{{ today()->toDateString() }}"
                           value="{{ old('spent_on', today()->toDateString()) }}">
                    @error('spent_on') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label class="field-label" for="description">البيان <span class="text-bad-700">*</span></label>
                <input id="description" name="description" type="text" maxlength="255" required class="field-input"
                       value="{{ old('description') }}" placeholder="تعبئة وقود لسيارة التوزيع">
                @error('description') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="field-label" for="payee">لمن دُفع</label>
                    <input id="payee" name="payee" type="text" maxlength="120" class="field-input"
                           value="{{ old('payee') }}">
                </div>
                <div>
                    <label class="field-label" for="reference">رقم الوصل</label>
                    <input id="reference" name="reference" type="text" maxlength="60" class="field-input"
                           value="{{ old('reference') }}">
                </div>
            </div>

            <div>
                <label class="field-label" for="branch_id">الفرع</label>
                <select id="branch_id" name="branch_id" class="field-input">
                    <option value="">بلا فرع</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}" @selected((int) old('branch_id') === $branch->id)>
                            {{ $branch->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            @if ($boxes->isNotEmpty())
                <div class="rounded-lg border border-ink-200 bg-ink-50 p-3">
                    <label class="flex items-start gap-2 text-sm">
                        <input type="checkbox" name="pay_now" value="1" class="mt-0.5 size-4 accent-[var(--brand)]"
                               onchange="document.getElementById('pay_box').hidden = ! this.checked">
                        <span>ادفعه الآن نقداً من الصندوق</span>
                    </label>
                    <div id="pay_box" hidden class="mt-3">
                        <select name="cash_box_id" class="field-input">
                            @foreach ($boxes as $item)
                                <option value="{{ $item->id }}">
                                    {{ $item->name }} ({{ number_format($item->balance) }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <p class="field-hint mt-2">
                        بلا دفع يبقى المصروف التزاماً مسجَّلاً ولا يتحرّك الصندوق.
                    </p>
                    @error('cash_box_id') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            @endif

            <button type="submit" class="btn-primary w-full">سجّل المصروف</button>
        </form>
    </section>
</div>
@endsection
