{{--
  شريط الإسناد الجماعي: يظهر عند اختيار صفوفٍ من _table — إسنادٌ وإخراجٌ للتوصيل،
  وطباعة الوصولات. $couriers مندوبو التوصيل بمناطقهم.
--}}
@if (auth()->user()->isStaff())
<form method="POST" action="{{ route('shipments.assign') }}" id="assign-form"
      class="fixed inset-x-0 bottom-0 z-40 border-t border-ink-200 bg-white/95 px-4 py-3 shadow-lg backdrop-blur"
      hidden data-bulk-bar>
    @csrf
    <div class="mx-auto flex max-w-screen-2xl flex-wrap items-center gap-3">
        <span class="text-sm font-semibold">
            <span data-bulk-count>0</span> شحنة مختارة
        </span>

        {{-- تغطية المندوب بجانب اسمه: التوزيع الصباحي يُصيب من أول مرّة --}}
        <select name="courier_id" class="field-input w-auto min-w-64" required>
            <option value="">اختر المندوب</option>
            @foreach ($couriers as $courier)
                @php $covers = $courier->zones->pluck('governorate.name_ar')->filter()->unique(); @endphp
                <option value="{{ $courier->id }}">
                    {{ $courier->name }}{{ $covers->isNotEmpty() ? ' — '.$covers->take(3)->implode('، ') : ' — بلا مناطق' }}
                </option>
            @endforeach
        </select>

        <button type="submit" class="btn-primary">إسناد وإخراج للتوصيل</button>
        {{-- الطباعة رابطٌ لا نموذج: نموذج GET كان سيحمل رمز الحماية في العنوان --}}
        <button type="button" class="btn-ghost" data-bulk-print="{{ route('shipments.labels') }}">
            <x-icon name="printer" class="size-5"/>
            طباعة الوصولات
        </button>
        <button type="button" class="btn-ghost" data-bulk-clear>إلغاء الاختيار</button>

        <span class="ms-auto text-xs text-ink-500">
            ما لم يُستلم بعد يُسجَّل استلامه ثم يخرج؛ والمسلَّمة والملغاة وما مع مندوبٍ تُتخطّى ويُقال لماذا.
        </span>
    </div>
</form>
@endif
