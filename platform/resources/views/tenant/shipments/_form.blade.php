{{--
  نموذج الشحنة، للإنشاء وللتعديل معاً: حقلٌ يُضاف هنا يصل الشاشتين.
  $shipment فارغة عند الإنشاء؛ وعند التعديل التاجر ثابت، والمحافظة ثابتةٌ
  بعد المخزن ($reroutable)، والأجرة الفارغة تبقى كما هي.

  مرتّبٌ كما تُقرأ ورقة الطلب وتُكتب: المستلم (الاسم ثم الهاتف)، ثم العنوان، ثم الطلب
  (المبلغ والعدد والملاحظة) — و«حفظ الشحنة» تحتها مباشرةً. لا أجور ولا عمولات في
  الطريق: الأجرة تُحسب من تسعيرة التاجر وهو يدفعها، وتعديلها استثناءٌ مطويٌّ مع ما
  يُكتب أحياناً تحت «خيارات إضافية».
--}}
@php
    $editing = $shipment !== null;
    // وصلٌ مطبوع مسبقاً تُدخَل شحنته (ShipmentController::create): رقمه، وتاجر دفتره إن أُسند
    $waybill = $editing ? null : ($waybill ?? null);
    // بعد الحفظ يعود النموذج فارغاً للشحنة التالية، وتاجرها مختارٌ (ShipmentController::store)
    $merchantId = old('merchant_id', $created->merchant_id ?? null);

    // «خيارات إضافية» تُفتح إن كان فيها ما كُتب أو ما رُفض: لا يُخفى خطأٌ ولا قيمة
    $filled = fn (string $field, $saved = null) => $errors->has($field) || filled(old($field, $saved));
    $extrasOpen = collect([
        'merchant_reference'  => $shipment?->merchant_reference,
        'recipient_phone_alt' => $shipment?->recipient_phone_alt,
        'description'         => $shipment?->description,
        'weight_grams'        => $shipment?->weight_grams ?: null,
        'delivery_fee'        => null,
        'extra_fee'           => $shipment?->extra_fee ?: null,
        'discount'            => $shipment?->discount ?: null,
        'fee_prepaid'         => null,
    ])->contains(fn ($saved, $field) => $filled($field, $saved))
        || old('is_fragile', $shipment?->is_fragile) || old('allow_open', $shipment?->allow_open);
    // «أجرة التوصيل»: فارغٌ يتبع حساب التاجر (يُحاسَب مقدّماً)، وفي التعديل حالها
    $prepaidChoice = (string) old('fee_prepaid', $editing ? ($shipment->fee_prepaid ? '1' : '0') : '');
    $required = '<span class="text-primary-600" aria-hidden="true">*</span>';
@endphp

<form method="POST" action="{{ $editing ? route('shipments.update', $shipment) : route('shipments.store') }}" id="shipment-form"
      data-quote-url="{{ route('pricing.quote') }}"
      @if ($editing)
          {{-- ما كانت عليه الشحنة: الأجرة الفارغة تبقى كما هي ما لم تتغيّر الوجهة أو الوزن --}}
          data-original="{{ json_encode($shipment->only(['governorate_id', 'city_id', 'weight_grams', 'delivery_fee'])) }}"
      @endif
      class="mx-auto max-w-3xl">
    @csrf
    @if ($editing) @method('PUT') @endif
    @if ($waybill) <input type="hidden" name="waybill" value="{{ $waybill->code }}"> @endif

    <section class="card overflow-hidden">

        {{-- التاجر: يُختار مرّةً ويبقى مختاراً للشحنات التالية --}}
        <div class="flex items-center gap-3 border-b border-ink-100 bg-ink-50/70 px-5 py-4 sm:px-7">
            <span class="panel-head-icon"><x-icon name="store" class="size-5"/></span>
            <div class="min-w-0 flex-1">
                @if ($editing || $waybill?->merchant)
                    {{-- الشحنة تبقى لتاجرها، ووصلُ دفترِ تاجرٍ لصاحب الدفتر --}}
                    @php $fixed = $editing ? $shipment->merchant : $waybill->merchant; @endphp
                    <span class="block text-xs text-ink-500">التاجر</span>
                    <span class="block font-heading text-base font-medium text-aeblack-950">{{ $fixed->business_name }}</span>
                    <input type="hidden" name="merchant_id" value="{{ $fixed->id }}">
                @else
                    <label class="sr-only" for="merchant_id">التاجر</label>
                    <select id="merchant_id" name="merchant_id" class="field-input" data-searchable required>
                        <option value="">اختر التاجر</option>
                        @foreach ($merchants as $merchant)
                            <option value="{{ $merchant->id }}" @selected((string) $merchantId === (string) $merchant->id)>
                                {{ $merchant->business_name }} — {{ $merchant->phone }}
                            </option>
                        @endforeach
                    </select>
                @endif
                @error('merchant_id') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="divide-y divide-ink-100">

            {{-- المستلم --}}
            <div class="px-5 py-5 sm:px-7">
                <div class="panel-head mb-3">
                    <span class="panel-head-icon"><x-icon name="user" class="size-5"/></span>
                    <h2 class="panel-head-title text-base">المستلم</h2>
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="field-label" for="recipient_name">اسم المستلم</label>
                        <input id="recipient_name" name="recipient_name" class="field-input" placeholder="اختياري" autocomplete="off"
                               value="{{ old('recipient_name', $shipment?->recipient_name === \App\Models\Shipment::UNNAMED_RECIPIENT ? '' : $shipment?->recipient_name) }}"
                               @unless ($editing) autofocus @endunless>
                        @error('recipient_name') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="field-label" for="recipient_phone">رقم الهاتف {!! $required !!}</label>
                        <input id="recipient_phone" name="recipient_phone" value="{{ old('recipient_phone', $shipment?->recipient_phone) }}"
                               class="field-input text-left" dir="ltr" inputmode="numeric" autocomplete="off"
                               placeholder="07xxxxxxxxx" required>
                        @error('recipient_phone') <p class="field-error">{{ $message }}</p> @enderror
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
                        <select id="governorate_id" name="governorate_id" class="field-input" required @disabled($editing && ! $reroutable)>
                            <option value="">اختر المحافظة</option>
                            @foreach ($governorates as $gov)
                                <option value="{{ $gov->id }}" @selected(old('governorate_id', $shipment?->governorate_id) == $gov->id)>
                                    {{ $gov->name_ar }}
                                </option>
                            @endforeach
                        </select>
                        @if ($editing && ! $reroutable)
                            <input type="hidden" name="governorate_id" value="{{ $shipment->governorate_id }}">
                            <p class="field-hint">الشحنة دخلت المخزن — لا تتغيّر محافظتها وهي في طريقها إليها.</p>
                        @endif
                        @error('governorate_id') <p class="field-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="field-label" for="city_id">المنطقة {!! $required !!}</label>
                        <select id="city_id" name="city_id" class="field-input" data-searchable data-old="{{ old('city_id', $shipment?->city_id) }}">
                            <option value="">اختر المحافظة أولاً</option>
                        </select>
                        @error('city_id') <p class="field-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="sm:col-span-2">
                        <label class="field-label" for="landmark">أقرب نقطة دالّة</label>
                        <input id="landmark" name="landmark" value="{{ old('landmark', $shipment?->landmark) }}" class="field-input"
                               placeholder="اختياري — مثال: مقابل جامع الرحمن · قرب مول بابل">
                        @error('landmark') <p class="field-error">{{ $message }}</p> @enderror
                    </div>

                    @if ($editing && filled($shipment->address))
                        {{-- عنوانٌ كُتب قبل أن يصير النموذج محافظةً ومنطقةً ونقطةً دالّة: يُصحَّح أو يُمسح --}}
                        <div class="sm:col-span-2">
                            <label class="field-label" for="address">العنوان المكتوب سابقاً</label>
                            <input id="address" name="address" value="{{ old('address', $shipment->address) }}" class="field-input">
                            <p class="field-hint">امسحه إن كانت المنطقة والنقطة الدالّة تكفيان.</p>
                            @error('address') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                    @endif
                </div>

                {{-- تسعيرة التاجر لا تطابق الوجهة: الأجرة تصير صفراً بلا خبر إن لم تُكتب يدوياً --}}
                <p class="mt-3 rounded-2xl bg-warn-50 px-4 py-2.5 text-sm text-warn-700" data-quote-warning hidden>
                    لا تسعيرة لهذا التاجر إلى هذه الوجهة — اكتب أجرة التوصيل من «خيارات إضافية» تحت.
                </p>
            </div>

            {{-- الطلب --}}
            <div class="px-5 py-5 sm:px-7">
                <div class="panel-head mb-3">
                    <span class="panel-head-icon"><x-icon name="box" class="size-5"/></span>
                    <h2 class="panel-head-title text-base">الطلب</h2>
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div class="sm:col-span-2">
                        <label class="field-label" for="cod_amount">المبلغ {!! $required !!}</label>
                        <div class="relative">
                            {{-- فارغةٌ لا صفر: يُكتب المبلغ مباشرةً بلا مسح، والصفر يُكتب قصداً للمدفوع مسبقاً --}}
                            <input id="cod_amount" name="cod_amount" type="number" min="0" step="1" placeholder="مثلاً 5 000"
                                   value="{{ old('cod_amount', $shipment?->cod_amount) }}" class="field-input ps-12 text-left text-base font-semibold placeholder:font-normal"
                                   dir="ltr" required>
                            <span class="absolute inset-y-0 end-4 flex items-center text-xs text-ink-400">د.ع</span>
                        </div>
                        <p class="field-hint">ما يدفعه الزبون للمندوب — 0 إن كان مدفوعاً مسبقاً.</p>
                        @error('cod_amount') <p class="field-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="field-label" for="pieces_count">العدد {!! $required !!}</label>
                        <input id="pieces_count" name="pieces_count" type="number" min="1" max="255"
                               value="{{ old('pieces_count', $shipment?->pieces_count ?? 1) }}" class="field-input text-left" dir="ltr" required>
                        @error('pieces_count') <p class="field-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="sm:col-span-3">
                        <label class="field-label" for="notes">الملاحظة</label>
                        <textarea id="notes" name="notes" rows="2" class="field-input"
                                  placeholder="اختياري — تُطبع على الوصل للمندوب، مثال: اتصل قبل الوصول">{{ old('notes', $shipment?->notes) }}</textarea>
                        @error('notes') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            {{-- ما يُكتب أحياناً: مطويٌّ حتى يُحتاج --}}
            <details class="group px-5 py-4 sm:px-7" data-extras @if ($extrasOpen) open @endif>
                <summary class="flex cursor-pointer list-none items-center gap-2 text-sm font-medium text-aeblack-800 [&::-webkit-details-marker]:hidden">
                    <x-icon name="sliders" class="size-4 text-ink-500"/>
                    خيارات إضافية
                    <span class="hidden font-normal text-ink-500 sm:inline">— هاتف بديل، رقم الطلب، المحتوى، الوزن، تعديل الأجرة</span>
                    <x-icon name="chevron-down" class="ms-auto size-4 text-ink-500 transition group-open:rotate-180"/>
                </summary>

                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <label class="field-label" for="recipient_phone_alt">هاتف بديل</label>
                        <input id="recipient_phone_alt" name="recipient_phone_alt" value="{{ old('recipient_phone_alt', $shipment?->recipient_phone_alt) }}"
                               class="field-input text-left" dir="ltr" inputmode="numeric" placeholder="07xxxxxxxxx">
                        @error('recipient_phone_alt') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="field-label" for="merchant_reference">رقم الطلب عند التاجر</label>
                        <input id="merchant_reference" name="merchant_reference" value="{{ old('merchant_reference', $shipment?->merchant_reference) }}"
                               class="field-input" placeholder="اختياري">
                        @error('merchant_reference') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="field-label" for="weight_grams">الوزن (غرام)</label>
                        <input id="weight_grams" name="weight_grams" type="number" min="0" placeholder="اختياري"
                               value="{{ old('weight_grams', $shipment?->weight_grams) }}" class="field-input" dir="ltr">
                        @error('weight_grams') <p class="field-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="sm:col-span-2">
                        <label class="field-label" for="description">وصف المحتوى</label>
                        <input id="description" name="description" value="{{ old('description', $shipment?->description) }}"
                               class="field-input" placeholder="مثال: ملابس — قطعتان">
                        @error('description') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex items-end gap-4 pb-2.5">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="is_fragile" value="1" @checked(old('is_fragile', $shipment?->is_fragile))
                                   class="rounded border-ink-300 text-[var(--brand)] focus:ring-brand-500">
                            قابل للكسر
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="allow_open" value="1" @checked(old('allow_open', $shipment?->allow_open))
                                   class="rounded border-ink-300 text-[var(--brand)] focus:ring-brand-500">
                            يُسمح بالفتح
                        </label>
                    </div>

                    {{-- الأجرة تُحسب من التسعيرة: تعديلها والرسوم والخصم استثناءٌ لا خطوة --}}
                    <div>
                        <label class="field-label" for="delivery_fee">أجرة التوصيل</label>
                        <div class="relative">
                            <input id="delivery_fee" name="delivery_fee" type="number" min="0" step="1"
                                   value="{{ old('delivery_fee') }}" class="field-input ps-12 text-left" dir="ltr"
                                   placeholder="من التسعيرة">
                            <span class="absolute inset-y-0 end-4 flex items-center text-xs text-ink-400">د.ع</span>
                        </div>
                        <p class="field-hint">
                            @if ($editing)
                                الحالية <span class="num">{{ number_format($shipment->delivery_fee) }}</span> — فارغٌ يُبقيها، وتُحسب من جديد إن تغيّرت الوجهة أو الوزن.
                            @else
                                فارغٌ يُحسب من تسعيرة التاجر.
                            @endif
                        </p>
                        @error('delivery_fee') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="field-label" for="extra_fee">رسوم إضافية</label>
                        <input id="extra_fee" name="extra_fee" type="number" min="0" step="1" placeholder="0"
                               value="{{ old('extra_fee', $shipment?->extra_fee) }}" class="field-input text-left" dir="ltr">
                        @error('extra_fee') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="field-label" for="discount">خصم</label>
                        <input id="discount" name="discount" type="number" min="0" step="1" placeholder="0"
                               value="{{ old('discount', $shipment?->discount) }}" class="field-input text-left" dir="ltr">
                        @error('discount') <p class="field-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="sm:col-span-3">
                        <label class="field-label" for="fee_prepaid">دفع الأجرة مقدّماً</label>
                        @if ($shipment?->prepaid_receipt_id)
                            <p class="rounded-2xl bg-ok-50 px-4 py-2.5 text-sm text-ok-700">
                                قُبضت مقدّماً (<span class="num">{{ number_format($shipment->prepaid_amount) }}</span> د.ع بإيصال
                                {{ $shipment->prepaidReceipt?->number }}) — لا تُخصم من مبلغها.
                            </p>
                        @else
                            <select id="fee_prepaid" name="fee_prepaid" class="field-input">
                                <option value="" @selected($prepaidChoice === '')>كما في حساب التاجر</option>
                                <option value="1" @selected($prepaidChoice === '1')>يدفعها التاجر مقدّماً — لا تُخصم من المبلغ</option>
                                <option value="0" @selected($prepaidChoice === '0')>تُخصم من المبلغ عند التسليم</option>
                            </select>
                        @endif
                        @error('fee_prepaid') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </details>
        </div>

        <div class="border-t border-ink-100 bg-ink-50/70 px-5 py-4 sm:px-7">
            <button type="submit" class="btn-primary w-full py-3 text-base">
                <x-icon name="check" class="size-5"/>
                {{ $editing ? 'حفظ التعديل' : 'حفظ الشحنة' }}
            </button>
            @unless ($editing || $waybill)
                <p class="mt-2 text-center text-xs text-ink-500">بعد الحفظ يفتح النموذج للشحنة التالية، والتاجر نفسه مختار.</p>
            @endunless
        </div>
    </section>
</form>

@php
    $citiesByGovernorate = $cities->groupBy('governorate_id')
        ->map(fn ($group) => $group->map(fn ($c) => ['id' => $c->id, 'name' => $c->name_ar])->values());
@endphp
<script type="application/json" id="cities-data">@json($citiesByGovernorate)</script>
