@extends('layouts.app')
@section('title', 'أجور المناطق والأطراف')

@section('content')
<div class="mb-5">
    <h1 class="page-title">أجور المناطق والأطراف</h1>
    <p class="mt-1 text-sm text-ink-500">
        لكل منطقةٍ أجرتها الخاصّة إن كانت أبعد من أن تُعامَل كمحافظتها، أو «طرفية» فتُسعَّر بمبلغ الأقضية والأطراف
        من <a href="{{ route('pricing.index') }}" class="text-[var(--brand)] hover:underline">التسعيرة</a>.
        والمندوبون يُسنَدون من <a href="{{ route('zones.index') }}" class="text-[var(--brand)] hover:underline">توزيع المندوبين</a>.
    </p>
</div>

<form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
    <div class="min-w-44">
        <label class="field-label" for="governorate_id">المحافظة</label>
        <select id="governorate_id" name="governorate_id" class="field-input" data-submit-on-change>
            @foreach ($governorates as $gov)
                <option value="{{ $gov->id }}" @selected($gov->id === $governorate->id)>{{ $gov->name_ar }}</option>
            @endforeach
        </select>
    </div>
    <div class="min-w-44 flex-1">
        <label class="field-label" for="q">اسم المنطقة</label>
        <input id="q" name="q" value="{{ request('q') }}" class="field-input">
    </div>
    <div class="min-w-40">
        <label class="field-label" for="courier_id">مندوب التوصيل</label>
        <select id="courier_id" name="courier_id" class="field-input" data-searchable>
            <option value="">الكل</option>
            @foreach ($couriers as $courier)
                <option value="{{ $courier->id }}" @selected((int) request('courier_id') === $courier->id)>{{ $courier->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="min-w-36">
        <label class="field-label" for="kind">النوع</label>
        <select id="kind" name="kind" class="field-input">
            <option value="">الكل</option>
            <option value="peripheral" @selected(request('kind') === 'peripheral')>الطرفية</option>
            <option value="fee" @selected(request('kind') === 'fee')>بأجرةٍ خاصّة</option>
        </select>
    </div>
    <label class="flex items-center gap-2 pb-2 text-sm">
        <input type="checkbox" name="unassigned" value="1" class="size-4 accent-[var(--brand)]" @checked(request()->boolean('unassigned'))>
        غير مسنودة لمندوب
    </label>
    <button type="submit" class="btn-primary">بحث</button>
</form>

{{-- المنطقة إلزامية في الشحنة: ما نقص من القائمة تضيفه الشركة لنفسها هنا --}}
<form method="POST" action="{{ route('areas.store') }}" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
    @csrf
    <input type="hidden" name="governorate_id" value="{{ $governorate->id }}">
    <div class="min-w-56 flex-1">
        <label class="field-label" for="name_ar">منطقة ناقصة في {{ $governorate->name_ar }}؟</label>
        <input id="name_ar" name="name_ar" value="{{ old('name_ar') }}" required minlength="2" maxlength="120" class="field-input"
               placeholder="اكتب اسمها كما يقوله أهلها">
        @error('name_ar') <p class="field-error">{{ $message }}</p> @enderror
    </div>
    <button type="submit" class="btn-primary">أضف المنطقة</button>
    <p class="w-full text-xs text-ink-500">تظهر لشركتك وحدها في الشحنات والإدخال السريع وملفّات Excel. والاسم الموجود — ولو بكتابةٍ أخرى — لا يتكرّر.</p>
</form>

<div class="mb-4 flex flex-wrap items-center gap-3 text-sm">
    <span class="text-ink-600">في {{ $governorate->name_ar }}: الطرفية {{ number_format($peripheralCount) }}</span>
    @if ($wholeGovernorate->isNotEmpty())
        <span class="chip chip-info">يغطّي المحافظة كلّها: {{ $wholeGovernorate->map(fn ($z) => $z->courier?->name)->filter()->implode('، ') }}</span>
    @endif
    {{-- دفعةً واحدة للمحافظة كلّها، لا للصفحة وحدها --}}
    <form method="POST" action="{{ route('areas.peripheral') }}" class="ms-auto flex gap-2">
        @csrf
        <input type="hidden" name="governorate_id" value="{{ $governorate->id }}">
        <button name="mode" value="all" class="btn-ghost py-1 text-xs" data-confirm="تصير كل مناطق {{ $governorate->name_ar }} طرفية؟">كلّها طرفية</button>
        <button name="mode" value="none" class="btn-ghost py-1 text-xs" data-confirm="تصير كل مناطق {{ $governorate->name_ar }} مركزاً؟">كلّها مركز</button>
    </form>
</div>

<form method="POST" action="{{ route('areas.update') }}" class="card overflow-hidden">
    @csrf
    <input type="hidden" name="governorate_id" value="{{ $governorate->id }}">
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th>المنطقة</th>
                    <th>أجرة النقل الخاصّة بها</th>
                    <th>طرفية؟</th>
                    <th>المندوبون المسنَدون</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($cities as $city)
                    @php $setting = $settings[$city->id] ?? null; $assigned = $byCity[$city->id] ?? collect(); @endphp
                    <tr>
                        <td class="font-medium">
                            {{ $city->name_ar }}
                            @if ($city->isCompanyOwn())
                                <span class="chip chip-info ms-1">أضافتها الشركة</span>
                                {{-- نموذج الحذف خارج نموذج الصفحة (لا نماذج متداخلة)، والزرّ يشير إليه --}}
                                <button type="submit" form="delete-area-{{ $city->id }}" class="ms-1 text-xs font-semibold text-bad-700 hover:underline"
                                        data-confirm="تُحذف «{{ $city->name_ar }}»؟ وإن حملتها شحنات تُخفى من القوائم فقط.">حذف</button>
                            @endif
                        </td>
                        <td>
                            <input name="rows[{{ $city->id }}][delivery_fee]" type="number" min="0" step="250" dir="ltr"
                                   class="field-input w-32 text-left" placeholder="كمحافظتها"
                                   value="{{ old("rows.{$city->id}.delivery_fee", $setting?->delivery_fee) }}"
                                   aria-label="أجرة {{ $city->name_ar }}">
                        </td>
                        <td>
                            <input type="hidden" name="rows[{{ $city->id }}][is_peripheral]" value="0">
                            <input type="checkbox" name="rows[{{ $city->id }}][is_peripheral]" value="1" class="size-4 accent-[var(--brand)]"
                                   @checked(old("rows.{$city->id}.is_peripheral", $setting?->is_peripheral)) aria-label="{{ $city->name_ar }} طرفية">
                        </td>
                        <td class="text-sm">
                            @if ($assigned->isNotEmpty())
                                {{ $assigned->map(fn ($z) => $z->courier?->name)->filter()->implode('، ') }}
                            @elseif ($wholeGovernorate->isNotEmpty())
                                <span class="text-ink-500">مع المحافظة</span>
                            @else
                                <span class="chip chip-warn">غير مسنودة</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-16 text-center text-ink-500">لا منطقة بهذا البحث.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($cities->isNotEmpty())
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-ink-100 px-5 py-3">
            <p class="text-xs text-ink-500">الأجرة الخاصّة تغلب أجرة المحافظة في التسعيرة العامّة؛ ومن له تسعيرةٌ خاصّة تبقى له.</p>
            <button type="submit" class="btn-primary">احفظ هذه الصفحة</button>
        </div>
    @endif
    @if ($cities->hasPages())
        <div class="border-t border-ink-100 px-4 py-3">{{ $cities->links() }}</div>
    @endif
</form>

@foreach ($cities->getCollection()->filter->isCompanyOwn() as $city)
    <form method="POST" action="{{ route('areas.destroy', $city) }}" id="delete-area-{{ $city->id }}" hidden>
        @csrf
        @method('DELETE')
    </form>
@endforeach
@endsection
