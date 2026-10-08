{{-- كل الكشوف بتواريخها، وما بيد المناديب الآن: شاشةٌ واحدة بتبويبين وبحثٍ واحد --}}
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="page-title">كشوف مناديب التوصيل</h1>
        <p class="mt-1 text-sm text-ink-500">
            كشفٌ لكل مندوبٍ عن كل يومٍ خرج فيه بشحنات — وما صارت إليه كلّ شحنة. والكشف الحيّ لما بيده الآن في تبويبه.
        </p>
    </div>
</div>

<nav class="tab-nav mb-3" aria-label="كشوف المناديب">
    <a href="{{ route('courier-manifests.index', array_filter(['q' => $filters['q'] ?: null])) }}"
       class="tab-link {{ $tab === 'history' ? 'tab-link-active' : '' }}" @if ($tab === 'history') aria-current="page" @endif>
        <x-icon name="archive" class="size-5"/> كل الكشوف
    </a>
    <a href="{{ route('courier-manifests.index', array_filter(['tab' => 'now', 'q' => $filters['q'] ?: null])) }}"
       class="tab-link {{ $tab === 'now' ? 'tab-link-active' : '' }}" @if ($tab === 'now') aria-current="page" @endif>
        <x-icon name="truck" class="size-5"/> بيدهم الآن
    </a>
</nav>

<form method="GET" action="{{ route('courier-manifests.index') }}" role="search" class="card mb-5 flex flex-wrap items-end gap-3 p-4">
    @if ($tab === 'now')
        <input type="hidden" name="tab" value="now">
    @endif
    <div class="min-w-56 flex-1">
        <label class="field-label" for="manifest-q">بحث عن مندوب</label>
        <input id="manifest-q" name="q" value="{{ $filters['q'] }}" class="field-input" placeholder="الاسم أو الكود أو الهاتف" autocomplete="off">
    </div>
    @if ($tab === 'history')
        <div class="min-w-48">
            <label class="field-label" for="manifest-courier">أو اختره</label>
            <select id="manifest-courier" name="courier_id" class="field-input" data-searchable>
                <option value="">كل المناديب</option>
                @foreach ($couriers as $option)
                    <option value="{{ $option->id }}" @selected($filters['courier_id'] === $option->id)>{{ $option->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="field-label" for="manifest-from">من تاريخ</label>
            <input id="manifest-from" name="from" type="date" value="{{ $filters['from'] }}" class="field-input">
        </div>
        <div>
            <label class="field-label" for="manifest-to">إلى تاريخ</label>
            <input id="manifest-to" name="to" type="date" value="{{ $filters['to'] }}" class="field-input">
        </div>
    @endif
    <button type="submit" class="btn-primary"><x-icon name="search" class="size-4"/> بحث</button>
    @if (array_filter($filters))
        <a href="{{ route('courier-manifests.index', $tab === 'now' ? ['tab' => 'now'] : []) }}" class="btn-ghost">إلغاء البحث</a>
    @endif
</form>
