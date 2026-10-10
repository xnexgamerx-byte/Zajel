@extends('layouts.app')
@section('title', 'دفاتر الوصولات المطبوعة')

@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="page-title">دفاتر الوصولات المطبوعة</h1>
        <p class="mt-1 text-sm text-ink-500">
            وصولاتٌ تُطبع قبل الشحن ويكتب عليها التاجر بيده: كل دفترٍ أرقامٌ متتالية لتاجرٍ بعينه، أو في المخزن حتى يُسنَد.
            والتاجر يطبع دفاتره من بوابته أيضاً («وصولات للطباعة») فتظهر هنا.
        </p>
    </div>
    <a href="{{ route('shipments.waybill') }}" class="btn-ghost">شحنة من وصلٍ مطبوع</a>
</div>

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    @if ($assigning)
        {{-- دفترٌ في المخزن يُسنَد لتاجر: يطبع اسمه عليه، ولا يُدخَل منه وصلٌ لغيره --}}
        <form method="POST" action="{{ route('waybill-books.assign', $assigning) }}" class="card space-y-4 p-5 lg:self-start">
            @csrf
            <h2 class="card-title">أسنِد الدفتر <span class="num">{{ $assigning->firstCode() }}–{{ $assigning->lastCode() }}</span></h2>
            <p class="card-hint">يُطبع اسم التاجر على وصولاته، ولا يُدخَل منها وصلٌ لغيره.</p>
            <div>
                <label class="field-label" for="assign_merchant">إلى التاجر</label>
                <select id="assign_merchant" name="merchant_id" class="field-input" data-searchable required>
                    <option value="">اختر التاجر</option>
                    @foreach ($merchants as $merchant)
                        <option value="{{ $merchant->id }}" @selected(old('merchant_id') == $merchant->id)>{{ $merchant->business_name }}</option>
                    @endforeach
                </select>
                @error('merchant_id') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn-primary flex-1">أسنِد</button>
                <a href="{{ request()->fullUrlWithoutQuery(['assign']) }}" class="btn-ghost">إلغاء</a>
            </div>
        </form>
    @else
        <form method="POST" action="{{ route('waybill-books.store') }}" class="card space-y-4 p-5 lg:self-start">
            @csrf
            <h2 class="card-title">دفترٌ جديد</h2>

            <div>
                <label class="field-label" for="merchant_id">للتاجر</label>
                <select id="merchant_id" name="merchant_id" class="field-input" data-searchable>
                    <option value="">في المخزن حتى يُسنَد</option>
                    @foreach ($merchants as $merchant)
                        <option value="{{ $merchant->id }}" @selected(old('merchant_id') == $merchant->id)>{{ $merchant->business_name }}</option>
                    @endforeach
                </select>
                @error('merchant_id') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="field-label" for="size">عدد الوصولات</label>
                <input id="size" name="size" type="number" min="1" max="{{ \App\Models\WaybillBook::MAX_SIZE }}" step="1"
                       value="{{ old('size', 50) }}" class="field-input num w-32" required>
                <p class="field-hint">حتى <span class="num">{{ \App\Models\WaybillBook::MAX_SIZE }}</span> في الدفتر؛ أرقامها متتالية بعد آخر دفتر.</p>
                @error('size') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="field-label" for="note">ملاحظة</label>
                <input id="note" name="note" maxlength="255" value="{{ old('note') }}" class="field-input"
                       placeholder="مثلاً: سُلّم مطبوعاً مع مندوب الاستلام">
                @error('note') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="btn-primary w-full">أصدر الدفتر</button>
        </form>
    @endif

    <section class="card overflow-hidden lg:col-span-2">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-ink-100 px-5 py-3">
            <h2 class="card-title">الدفاتر</h2>
            <form method="GET" action="{{ route('waybill-books.index') }}" class="flex flex-wrap items-center gap-2">
                <label class="sr-only" for="filter-merchant">التاجر</label>
                <select id="filter-merchant" name="merchant_id" class="field-input h-10 w-auto py-1" data-submit-on-change>
                    <option value="">كل التجّار</option>
                    @foreach ($merchants as $merchant)
                        <option value="{{ $merchant->id }}" @selected(request()->integer('merchant_id') === $merchant->id)>{{ $merchant->business_name }}</option>
                    @endforeach
                </select>
                <label class="chip cursor-pointer {{ request('assigned') === 'no' ? 'chip-info' : 'chip-mute' }}">
                    <input type="checkbox" name="assigned" value="no" class="sr-only" @checked(request('assigned') === 'no') data-submit-on-change>
                    في المخزن
                </label>
            </form>
        </div>

        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr><th>الأرقام</th><th>التاجر</th><th>استُعمل</th><th>أصدره</th><th>اطبع</th></tr>
                </thead>
                <tbody>
                    @forelse ($books as $book)
                        <tr>
                            <td>
                                <span class="num font-semibold whitespace-nowrap">{{ $book->firstCode() }}–{{ $book->lastCode() }}</span>
                                <span class="block text-xs text-ink-500">{{ \App\Support\Arabic::waybills($book->size) }}</span>
                            </td>
                            <td class="text-sm">
                                @if ($book->merchant)
                                    {{ $book->merchant->business_name }}
                                @elseif ($book->used_count === 0)
                                    {{-- في المخزن: يُسنَد لتاجرٍ قبل أن يُستعمل منه وصل --}}
                                    <span class="text-ink-500">في المخزن ·</span>
                                    <a href="{{ request()->fullUrlWithQuery(['assign' => $book->id]) }}"
                                       class="font-semibold text-[var(--brand)] hover:underline">أسنِده لتاجر</a>
                                @else
                                    <span class="text-ink-500">في المخزن · استُعمل منه، فلا يُسنَد</span>
                                @endif
                            </td>
                            <td class="text-sm whitespace-nowrap">
                                <span class="num font-semibold">{{ number_format($book->used_count) }}</span>
                                <span class="text-ink-500">من <span class="num">{{ number_format($book->size) }}</span></span>
                            </td>
                            <td class="text-sm">
                                {{ ['portal' => 'التاجر من بوابته', 'app' => 'التاجر من تطبيقه'][$book->source] ?? ($book->creator?->name ?? '—') }}
                                <span class="num block text-xs whitespace-nowrap text-ink-500">{{ $book->created_at->format('Y-m-d') }}</span>
                                @if ($book->note)
                                    <span class="block text-xs text-ink-500">{{ $book->note }}</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap">
                                @foreach (\App\Models\WaybillBook::PRINT_SIZES as $key => [$w, $h])
                                    <a href="{{ route('waybill-books.print', ['book' => $book, 'size' => $key]) }}" target="_blank"
                                       class="text-sm font-semibold text-[var(--brand)] hover:underline"><span class="num">{{ $w }}×{{ $h }}</span></a>@if (! $loop->last)<span class="px-1 text-ink-300">·</span>@endif
                                @endforeach
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-10 text-center text-ink-500">لا دفاتر بعد.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($books->hasPages())
            <div class="border-t border-ink-100 px-4 py-3">{{ $books->links() }}</div>
        @endif
    </section>
</div>
@endsection
