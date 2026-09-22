@extends('layouts.platform')
@section('title', 'الفواتير')

@section('content')
<div class="mb-5">
    <h1 class="text-xl font-bold">الفواتير</h1>
    <p class="mt-1 text-sm text-slate-500">
        اشتراك الشركة وعمولة شحناتها المسلَّمة. كل شحنة تدخل فاتورة واحدة فقط.
    </p>
</div>

<div class="mb-5 grid grid-cols-1 gap-3 md:grid-cols-3">
    @foreach ([
        ['إجمالي ما فُوتِر', (int) ($totals->billed ?? 0), 'text-slate-900'],
        ['المحصَّل', (int) ($totals->collected ?? 0), 'text-emerald-700'],
        ['المستحقّ غير المدفوع', (int) ($totals->outstanding ?? 0), 'text-amber-700'],
    ] as [$label, $value, $tone])
        <div class="card p-4">
            <div class="text-xs font-medium text-slate-500">{{ $label }}</div>
            <div class="mt-1 text-2xl font-bold {{ $tone }}" dir="ltr">
                {{ number_format($value) }} <span class="text-sm font-medium text-slate-500">د.ع</span>
            </div>
        </div>
    @endforeach
</div>

<div class="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
    <form method="POST" action="{{ route('admin.invoices.generate') }}" class="card p-4 lg:col-span-1">
        @csrf
        <h2 class="mb-3 text-sm font-bold">إصدار فواتير شهر</h2>
        <div class="flex items-end gap-2">
            <div class="flex-1">
                <label class="field-label" for="month">الشهر</label>
                <input id="month" name="month" type="month" class="field-input text-left" dir="ltr" required
                       value="{{ old('month', $lastMonth->format('Y-m')) }}">
                @error('month') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn-primary">أصدر</button>
        </div>
        <p class="mt-2 text-xs text-slate-500">
            الشركات المفوترة سلفاً عن الشهر نفسه تُتخطّى — لا ازدواج.
        </p>
    </form>

    <form method="GET" class="card flex flex-wrap items-end gap-3 p-4 lg:col-span-2">
        <div>
            <label class="field-label" for="status">الحالة</label>
            <select id="status" name="status" class="field-input">
                <option value="">الكل</option>
                @foreach (['issued' => 'صادرة', 'paid' => 'مدفوعة', 'overdue' => 'متأخّرة',
                           'draft' => 'مسوّدة', 'void' => 'ملغاة'] as $v => $l)
                    <option value="{{ $v }}" @selected(request('status') === $v)>{{ $l }}</option>
                @endforeach
            </select>
        </div>
        <div class="min-w-52">
            <label class="field-label" for="company_id">الشركة</label>
            <select id="company_id" name="company_id" class="field-input">
                <option value="">الكل</option>
                @foreach ($companies as $company)
                    <option value="{{ $company->id }}" @selected((int) request('company_id') === $company->id)>
                        {{ $company->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn-primary">تطبيق</button>
        <a href="{{ route('admin.invoices.index') }}" class="btn-ghost">مسح</a>
    </form>
</div>

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                <tr>
                    <th class="px-4 py-3 text-start font-semibold">الرقم</th>
                    <th class="px-4 py-3 text-start font-semibold">الشركة</th>
                    <th class="px-4 py-3 text-start font-semibold">الفترة</th>
                    <th class="px-4 py-3 text-start font-semibold">اشتراك</th>
                    <th class="px-4 py-3 text-start font-semibold">شحنات</th>
                    <th class="px-4 py-3 text-start font-semibold">عمولة</th>
                    <th class="px-4 py-3 text-start font-semibold">الإجمالي</th>
                    <th class="px-4 py-3 text-start font-semibold">المتبقّي</th>
                    <th class="px-4 py-3 text-start font-semibold">الحالة</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($invoices as $invoice)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.invoices.show', $invoice) }}"
                               class="font-mono text-xs font-semibold text-brand-700 hover:underline" dir="ltr">
                                {{ $invoice->number }}
                            </a>
                        </td>
                        <td class="px-4 py-3">{{ $invoice->company->name }}</td>
                        <td class="px-4 py-3 text-xs text-slate-500" dir="ltr">
                            {{ $invoice->period_start->format('Y-m') }}
                        </td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">
                            {{ number_format($invoice->subscription_amount) }}
                        </td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">
                            {{ number_format($invoice->billable_shipments) }}
                        </td>
                        <td class="px-4 py-3 text-slate-600" dir="ltr">
                            {{ number_format($invoice->commission_amount) }}
                        </td>
                        <td class="px-4 py-3 font-bold" dir="ltr">{{ number_format($invoice->total) }}</td>
                        <td class="px-4 py-3 font-semibold {{ $invoice->balanceDue() > 0 ? 'text-amber-700' : 'text-emerald-700' }}"
                            dir="ltr">{{ number_format($invoice->balanceDue()) }}</td>
                        <td class="px-4 py-3"><x-invoice-status :status="$invoice->status" /></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-4 py-16 text-center text-slate-500">
                            لا فواتير بعد. أصدر فواتير شهر من الأعلى.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($invoices->hasPages())
        <div class="border-t border-slate-100 px-4 py-3">{{ $invoices->links() }}</div>
    @endif
</div>
@endsection
