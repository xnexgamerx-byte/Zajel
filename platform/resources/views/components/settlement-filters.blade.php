@props([
    'filters',              // App\Support\Money\SettlementFilters
    'actors',               // من بنى الكشوف أو أقفلها أو دفعها
    'action',               // رابط الشاشة نفسها
    'placeholder' => 'ابحث بالاسم أو الكود أو الهاتف',
    'statuses' => ['draft' => 'مسودّة', 'confirmed' => 'مُقفَل', 'paid' => 'مدفوع'],
])

{{-- البحث والفلترة فوق القائمة كما هي: الاسم يقصر المنتظرين والكشوف معاً --}}
<form method="GET" action="{{ $action }}" role="search" class="card mb-5 p-4">
    <div class="grid grid-cols-1 items-end gap-3 sm:grid-cols-2 lg:grid-cols-6">
        <div class="lg:col-span-2">
            <label class="field-label" for="filter-q">بحث</label>
            <input id="filter-q" name="q" value="{{ $filters->q }}" class="field-input" placeholder="{{ $placeholder }}" autocomplete="off">
        </div>
        <div>
            <label class="field-label" for="filter-status">الحالة</label>
            <select id="filter-status" name="status" class="field-input">
                <option value="">الكل</option>
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}" @selected($filters->status === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="field-label" for="filter-from">من تاريخ</label>
            <input id="filter-from" name="from" type="date" value="{{ $filters->from }}" class="field-input">
        </div>
        <div>
            <label class="field-label" for="filter-to">إلى تاريخ</label>
            <input id="filter-to" name="to" type="date" value="{{ $filters->to }}" class="field-input">
        </div>
        <div>
            <label class="field-label" for="filter-by">بواسطة</label>
            <select id="filter-by" name="by" class="field-input">
                <option value="">أيّ محاسب</option>
                @foreach ($actors as $actor)
                    <option value="{{ $actor->id }}" @selected($filters->by === $actor->id)>{{ $actor->name }}</option>
                @endforeach
            </select>
        </div>
    </div>
    <div class="mt-3 flex flex-wrap items-center gap-2">
        <button type="submit" class="btn-primary">
            <x-icon name="search" class="size-4"/> بحث
        </button>
        @if ($filters->active())
            <a href="{{ $action }}" class="btn-ghost">إلغاء البحث</a>
        @endif
        {{ $slot }}
    </div>
</form>
