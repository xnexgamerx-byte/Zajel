{{--
  شريط التحديث من القائمة: يظهر عند اختيار صفوفٍ من _table — تغيير حالتها بلا
  دخول كلٍّ منها (ومنه إسنادها لمندوبٍ وإخراجها للتوصيل)، وطباعة وصولاتها.
  و«اختر الكل» يأخذ كل ما يطابق البحث لا الصفحة وحدها (ChangeStatusInBulk::MAX).

  $bulkTargets ما يملكه الموظّف من حالات، و$bulkSources من أين تنتقل كلٌّ منها،
  و$statusCounts عدد كل حالٍ في البحث كلّه — فيُعرض على كل حالةٍ كم سيتحرّك.
  $couriers مندوبو التوصيل بمناطقهم، و$reasons أسباب عدم التسليم.
--}}
@if (auth()->user()->isStaff())
<form method="POST" action="{{ route('shipments.bulk-status') }}" id="assign-form"
      class="fixed inset-x-0 bottom-0 z-40 border-t border-ink-200 bg-white/95 px-4 py-3 shadow-lg backdrop-blur"
      hidden data-bulk-bar
      data-total="{{ $shipments->total() }}" data-max="{{ \App\Actions\Shipments\ChangeStatusInBulk::MAX }}"
      data-sources="{{ json_encode($bulkSources) }}" data-counts="{{ json_encode((object) $statusCounts) }}">
    @csrf
    {{-- «الكل»: البحث نفسه وعدد ما رآه الموظّف، فلا يُحدَّث ما دخل القائمة بعد فتحها --}}
    <fieldset data-bulk-all disabled hidden>
        <input type="hidden" name="all" value="1">
        <input type="hidden" name="expected" value="{{ $shipments->total() }}">
        @foreach ($filters as $name => $value)
            <input type="hidden" name="filters[{{ $name }}]" value="{{ $value }}">
        @endforeach
    </fieldset>

    <div class="mx-auto flex max-w-screen-2xl flex-wrap items-center gap-2">
        <span class="text-sm font-semibold">
            <span data-bulk-count>0</span> مختارة<span data-bulk-scope hidden> من كل نتائج البحث</span>
        </span>
        @if ($shipments->total() > $shipments->count() && $shipments->total() <= \App\Actions\Shipments\ChangeStatusInBulk::MAX)
            <button type="button" class="btn-ghost h-9 px-3 text-sm" data-bulk-all-toggle hidden>
                اختر كل الـ{{ number_format($shipments->total()) }}
            </button>
        @endif

        @if ($bulkTargets !== [])
            <select name="status" class="field-input w-auto" required data-bulk-status aria-label="الحالة الجديدة">
                <option value="">الحالة الجديدة…</option>
                @foreach ($bulkTargets as $value => $label)
                    <option value="{{ $value }}" data-label="{{ $label }}">{{ $label }}</option>
                @endforeach
            </select>

            {{-- تغطية المندوب بجانب اسمه: التوزيع الصباحي يُصيب من أول مرّة --}}
            <select name="courier_id" class="field-input w-auto min-w-56" data-bulk-when="out_for_delivery" hidden disabled
                    aria-label="المندوب">
                <option value="">اختر المندوب</option>
                @foreach ($couriers as $courier)
                    @php $covers = $courier->zones->pluck('governorate.name_ar')->filter()->unique(); @endphp
                    <option value="{{ $courier->id }}">
                        {{ $courier->name }}{{ $covers->isNotEmpty() ? ' — '.$covers->take(3)->implode('، ') : ' — بلا مناطق' }}
                    </option>
                @endforeach
            </select>

            <select name="failure_reason_id" class="field-input w-auto" data-bulk-when="failed_attempt" hidden disabled
                    aria-label="سبب عدم التسليم">
                <option value="">السبب</option>
                @foreach ($reasons as $reason)
                    <option value="{{ $reason->id }}" data-requires-note="{{ $reason->requires_note ? '1' : '0' }}">{{ $reason->name_ar }}</option>
                @endforeach
            </select>

            <input name="note" maxlength="500" class="field-input w-auto min-w-48" placeholder="ملاحظة (اختيارية)"
                   data-bulk-when="failed_attempt postponed returning cancelled" hidden disabled aria-label="ملاحظة">

            <button type="submit" class="btn-primary">تحديث</button>
        @endif

        {{-- الطباعة رابطٌ لا نموذج: نموذج GET كان سيحمل رمز الحماية في العنوان --}}
        <button type="button" class="btn-ghost" data-bulk-print="{{ route('shipments.labels') }}">
            <x-icon name="printer" class="size-5"/>
            طباعة الوصولات
        </button>
        <button type="button" class="btn-ghost" data-bulk-clear>إلغاء الاختيار</button>
    </div>

    @if ($bulkTargets !== [])
        <p class="mx-auto mt-1.5 hidden max-w-screen-2xl text-xs text-ink-500 sm:block">
            بجانب كل حالةٍ عدد ما سيتحرّك إليها، وما لا يصحّ نقله يُتخطّى ويُقال لماذا.
            الواصل الجزئي والمفقود والتالف من صفحة الشحنة.
        </p>
    @endif
</form>
@endif
