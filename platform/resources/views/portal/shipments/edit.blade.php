@extends('layouts.portal')
@section('title', 'تعديل الشحنة ' . $shipment->number)

@section('content')
{{--
  تعديل التاجر لشحنته (docs/plan/113): نموذج إنشائها نفسه بقيمها، ما دامت لم يستلمها المندوب — وبعد الاستلام لا تعديل
  (docs/plan/118): تفتح هذه الصفحة لما يُعدَّل وحده.
--}}
@php
    $required = '<span class="text-primary-600" aria-hidden="true">*</span>';
    $goods = $merchant->goodsTypeLabel();
    $nameRequired = \App\Support\ShipmentFields::isRequired('recipient_name');
    $name = $shipment->recipient_name === \App\Models\Shipment::UNNAMED_RECIPIENT ? '' : $shipment->recipient_name;
@endphp
<div class="mx-auto mb-5 flex max-w-3xl flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="page-title">تعديل الشحنة <span class="num" dir="ltr">{{ $shipment->number }}</span></h1>
        <p class="mt-1 text-sm text-ink-500">
            لم يستلمها المندوب بعد: عدّل ما تشاء، والأجرة تُحسب من تسعيرتك.
        </p>
    </div>
    <a href="{{ route('portal.shipments.show', $shipment) }}" class="btn-ghost">رجوع</a>
</div>

<form method="POST" action="{{ route('portal.shipments.update', $shipment) }}" class="mx-auto max-w-3xl">
    @csrf
    @method('PUT')

    <section class="card overflow-hidden">
        <div class="divide-y divide-ink-100">

            {{-- الزبون --}}
            <div class="px-5 py-5 sm:px-7">
                <div class="panel-head mb-3">
                    <span class="panel-head-icon"><x-icon name="user" class="size-5"/></span>
                    <h2 class="panel-head-title text-base">الزبون</h2>
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <label class="field-label" for="recipient_name">اسم الزبون {!! $nameRequired ? $required : '' !!}</label>
                        <input id="recipient_name" name="recipient_name" class="field-input" autocomplete="off" @required($nameRequired)
                               value="{{ old('recipient_name', $name) }}">
                        @error('recipient_name') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="field-label" for="recipient_phone">رقم الهاتف الأساسي {!! $required !!}</label>
                        <input id="recipient_phone" name="recipient_phone" class="field-input text-left" dir="ltr" inputmode="numeric"
                               autocomplete="off" placeholder="07xxxxxxxxx" required value="{{ old('recipient_phone', $shipment->recipient_phone) }}">
                        @error('recipient_phone') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="field-label" for="recipient_phone_alt">رقم الهاتف الثانوي</label>
                        <input id="recipient_phone_alt" name="recipient_phone_alt" class="field-input text-left" dir="ltr" inputmode="numeric"
                               autocomplete="off" placeholder="اختياري" value="{{ old('recipient_phone_alt', $shipment->recipient_phone_alt) }}">
                        @error('recipient_phone_alt') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            {{-- العنوان --}}
            <div class="px-5 py-5 sm:px-7">
                <div class="panel-head mb-3">
                    <span class="panel-head-icon"><x-icon name="pin" class="size-5"/></span>
                    <h2 class="panel-head-title text-base">العنوان</h2>
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="field-label" for="governorate_id">المحافظة {!! $required !!}</label>
                            <select id="governorate_id" name="governorate_id" class="field-input" required>
                                @foreach ($governorates as $gov)
                                    <option value="{{ $gov->id }}" @selected((int) old('governorate_id', $shipment->governorate_id) === $gov->id)>{{ $gov->name_ar }}</option>
                                @endforeach
                            </select>
                            @error('governorate_id') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="field-label" for="city_id">المنطقة {!! $required !!}</label>
                            <select id="city_id" name="city_id" class="field-input" data-searchable data-old="{{ old('city_id', $shipment->city_id) }}">
                                <option value="">اختر المحافظة أولاً</option>
                            </select>
                            @error('city_id') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                    @if (filled($shipment->address) || filled(old('address')))
                        <div class="sm:col-span-2">
                            <label class="field-label" for="address">العنوان</label>
                            <input id="address" name="address" class="field-input" maxlength="500" value="{{ old('address', $shipment->address) }}">
                            @error('address') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                    @endif
                    <div class="sm:col-span-2">
                        <label class="field-label" for="landmark">أقرب نقطة دالّة</label>
                        <input id="landmark" name="landmark" class="field-input" placeholder="اختياري — مثال: مقابل جامع الرحمن"
                               value="{{ old('landmark', $shipment->landmark) }}">
                        @error('landmark') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            {{-- الطلب --}}
            <div class="px-5 py-5 sm:px-7">
                <div class="panel-head mb-3">
                    <span class="panel-head-icon"><x-icon name="box" class="size-5"/></span>
                    <h2 class="panel-head-title text-base">الطلب</h2>
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div class="sm:col-span-2">
                            <label class="field-label" for="cod_amount">السعر مع التوصيل {!! $required !!}</label>
                            <div class="relative">
                                <input id="cod_amount" name="cod_amount" type="number" min="0" step="1" required
                                       class="field-input ps-12 text-left text-base font-semibold" dir="ltr" value="{{ old('cod_amount', $shipment->cod_amount) }}">
                                <span class="absolute inset-y-0 end-4 flex items-center text-xs text-ink-400">د.ع</span>
                            </div>
                            <p class="field-hint">ما يدفعه الزبون كاملاً مع أجرة التوصيل — 0 إن دفع لك مسبقاً.</p>
                            @error('cod_amount') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="field-label" for="pieces_count">عدد القطع {!! $required !!}</label>
                            <input id="pieces_count" name="pieces_count" type="number" min="1" max="255" required
                                   class="field-input text-left" dir="ltr" value="{{ old('pieces_count', $shipment->pieces_count) }}">
                            @error('pieces_count') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="field-label" for="description">نوع البضاعة</label>
                            <input id="description" name="description" class="field-input" maxlength="2000"
                                   placeholder="مثلاً: {{ $goods && ! in_array($merchant->goods_type, ['general', 'other'], true) ? $goods : 'ملابس' }}"
                                   value="{{ old('description', $shipment->description) }}">
                            @error('description') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="field-label" for="size">حجم الطلب</label>
                            <select id="size" name="size" class="field-input">
                                @foreach (\App\Models\Shipment::SIZES as $value => $label)
                                    <option value="{{ $value }}" @selected(old('size', $shipment->size) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('size') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="field-label" for="type">نوع الطلب</label>
                            <select id="type" name="type" class="field-input">
                                @foreach (\App\Models\Shipment::TYPES as $value => $label)
                                    <option value="{{ $value }}" @selected(old('type', $shipment->type) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('type') <p class="field-error">{{ $message }}</p> @enderror
                        </div>

                    <div class="sm:col-span-3">
                        <label class="field-label" for="notes">الملاحظات</label>
                        <textarea id="notes" name="notes" rows="2" class="field-input"
                                  placeholder="اختياري — للمندوب، مثال: اتصل قبل الوصول">{{ old('notes', $shipment->notes) }}</textarea>
                        @error('notes') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="border-t border-ink-100 bg-ink-50/70 px-5 py-4 sm:px-7">
            <button type="submit" class="btn-primary w-full py-3 text-base">
                <x-icon name="check" class="size-5"/>
                حفظ التعديل
            </button>
        </div>
    </section>
</form>

@if ($deletable)
    {{-- «احذف الشحنة» (docs/plan/117): ما دامت لم تصلنا — تذهب إلى الشحنات الممسوحة عند الشركة وتُسترجع منها --}}
    <form method="POST" action="{{ route('portal.shipments.destroy', $shipment) }}" class="mx-auto mt-4 max-w-3xl">
        @csrf
        @method('DELETE')
        <section class="card p-5 sm:px-7">
            <label class="field-label" for="reason">لماذا تحذفها؟</label>
            <input id="reason" name="reason" class="field-input" maxlength="255" placeholder="اختياري — مثلاً: ألغى الزبون الطلب">
            @error('reason') <p class="field-error">{{ $message }}</p> @enderror
            <button type="submit" class="btn-ghost mt-3 w-full py-3 text-base text-bad-700 ring-1 ring-bad-200"
                    data-confirm="حذف الشحنة {{ $shipment->number }}؟ لا تُستلم ولا تُوصل بعدها.">
                <x-icon name="trash" class="size-5"/>
                احذف الشحنة
            </button>
            <p class="mt-2 text-center text-xs text-ink-500">تُحذف ما دامت لم يستلمها المندوب.</p>
        </section>
    </form>
@endif

    @php
        $citiesByGovernorate = $cities->groupBy('governorate_id')
            ->map(fn ($g) => $g->map(fn ($c) => ['id' => $c->id, 'name' => $c->name_ar])->values());
    @endphp
    <script type="application/json" id="cities-data">@json($citiesByGovernorate)</script>
@endsection
