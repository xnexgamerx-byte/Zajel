@extends('layouts.app')
@section('title', 'بيانات الشركة')

@section('content')
<div class="mb-5">
    <h1 class="page-title">بيانات الشركة</h1>
    <p class="mt-1 text-sm text-ink-500">ما يراه تجّارك ومناديبك: كيف يصلونك، ولون واجهتك.</p>
</div>

<form method="POST" action="{{ route('settings.company.update') }}" class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    @csrf
    @method('PUT')

    <section class="card space-y-4 p-5 lg:col-span-2">
        <div>
            <span class="field-label">الاسم</span>
            <p class="font-semibold">{{ $company->name }}</p>
            {{-- الاسم والنطاق هويّة الاشتراك: تغييرهما من المنصّة لا من هنا --}}
            <p class="text-xs text-ink-500">الاسم والرابط <span class="num">{{ $company->slug }}.{{ config('zajel.tenant_domain') }}</span> يُغيَّران بطلبٍ إلى إدارة المنصّة.</p>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label class="field-label" for="phone">هاتف الشركة</label>
                <input id="phone" name="phone" class="field-input num" inputmode="tel" placeholder="07xxxxxxxxx"
                       value="{{ old('phone', $company->phone) }}">
            </div>
            <div>
                <label class="field-label" for="email">البريد</label>
                <input id="email" name="email" type="email" class="field-input num" value="{{ old('email', $company->email) }}">
            </div>
        </div>

        <div class="rounded-xl border border-ink-200 p-4">
            <h2 class="card-title">واتساب الدعم</h2>
            <p class="card-hint mb-3">يظهر زرًّا في بوّابة التاجر وتطبيق المندوب وصفحة التتبّع يفتح محادثة واتساب مع هذا الرقم. ولكل محافظةٍ رقمها إن شئت من «إعدادات المحافظات».</p>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="field-label" for="support_whatsapp">الرقم</label>
                    <input id="support_whatsapp" name="support_whatsapp" class="field-input num" inputmode="tel" placeholder="07xxxxxxxxx"
                           value="{{ old('support_whatsapp', $company->setting('support.whatsapp')) }}">
                </div>
                <div>
                    <label class="field-label" for="support_complaints">هاتف الشكاوى (اختياري)</label>
                    <input id="support_complaints" name="support_complaints" class="field-input num" inputmode="tel" placeholder="07xxxxxxxxx"
                           value="{{ old('support_complaints', $company->setting('support.complaints')) }}">
                </div>
                <div>
                    <label class="field-label" for="support_hours">ساعات الدعم (اختياري)</label>
                    <input id="support_hours" name="support_hours" class="field-input" maxlength="120"
                           placeholder="من السبت إلى الخميس، ٩ صباحاً – ٥ مساءً"
                           value="{{ old('support_hours', $company->setting('support.hours')) }}">
                </div>
            </div>
            @if ($link = \App\Support\Phone::whatsappUrl($company->setting('support.whatsapp')))
                <a href="{{ $link }}" target="_blank" rel="noopener" class="mt-3 inline-block text-sm text-[var(--brand)] hover:underline">جرّب الرابط ←</a>
            @endif
        </div>

        {{-- ساعات مراسلة التاجر وآخر موعدٍ للتوصيل (docs/plan/39) --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="rounded-xl border border-ink-200 p-4">
                <h2 class="card-title">ساعات مراسلة التجّار</h2>
                <p class="card-hint mb-3">
                    يراسل التاجر الشركة من البوابة في هذه الساعات وحدها، بتوقيت بغداد. وخارجها لا تُرسَل رسالته ويرى متى تُفتح.
                    وموظّفوك يردّون متى شاؤوا.
                </p>
                @php
                    $from = (int) old('merchant_from', \App\Support\MerchantHours::from($company));
                    $to = (int) old('merchant_to', \App\Support\MerchantHours::to($company));
                @endphp
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="field-label" for="merchant_from">من الساعة</label>
                        <select id="merchant_from" name="merchant_from" class="field-input">
                            @for ($h = 0; $h <= 23; $h++)
                                <option value="{{ $h }}" @selected($from === $h)>{{ \App\Support\MerchantHours::label($h) }}</option>
                            @endfor
                        </select>
                    </div>
                    <div>
                        <label class="field-label" for="merchant_to">إلى الساعة</label>
                        <select id="merchant_to" name="merchant_to" class="field-input">
                            @for ($h = 1; $h <= 24; $h++)
                                <option value="{{ $h }}" @selected($to === $h)>{{ \App\Support\MerchantHours::label($h) }}{{ $h === 24 ? ' (آخر اليوم)' : '' }}</option>
                            @endfor
                        </select>
                    </div>
                </div>
                @error('merchant_to') <p class="field-error">{{ $message }}</p> @enderror
                <p class="mt-2 text-xs text-ink-500">من 12 ليلاً إلى 12 ليلاً (آخر اليوم) = على مدار اليوم.</p>
            </div>

            <div class="rounded-xl border border-ink-200 p-4">
                <label class="card-title block" for="deadline_hours">آخر موعد للتوصيل</label>
                <p class="card-hint mb-3">
                    ساعاتٌ من استلام الشحنة من التاجر. ما تجاوزها ولم يُسلَّم يظهر في «التنبيهات التشغيلية» مرتّباً بأولويته،
                    وبها تُقاس الشحنات المتوقّفة عند نقطة انتقال والمبالغ التي لم تُسلَّم.
                </p>
                <div class="flex items-center gap-2">
                    <input id="deadline_hours" name="deadline_hours" type="number" min="1" max="240" class="field-input num w-28"
                           value="{{ old('deadline_hours', \App\Support\DeliveryDeadline::hours($company)) }}">
                    <span class="text-sm text-ink-600">ساعة</span>
                </div>
                @error('deadline_hours') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="rounded-xl border border-ink-200 p-4">
            <label class="card-title block" for="waybill_terms">شروط الوصل المطبوع</label>
            <p class="card-hint mb-3">
                تُطبع بخطٍّ صغير أسفل الوصولات التي يطبعها التجّار ويكتبون عليها بأيديهم: سطرٌ لكلّ شرط، وأربعة أسطرٍ قصيرة تكفي.
                وإن تُركت فارغةً طُبعت الشروط المعتادة الظاهرة في الحقل.
            </p>
            <textarea id="waybill_terms" name="waybill_terms" rows="4" maxlength="600" class="field-input"
                      placeholder="{{ implode("\n", \App\Models\WaybillBook::DEFAULT_TERMS) }}">{{ old('waybill_terms', $company->setting('waybill.terms')) }}</textarea>
            @error('waybill_terms') <p class="field-error">{{ $message }}</p> @enderror
        </div>

        {{-- طرق دفع مستحقّات التجّار في «طلب محاسبة» من بوابتهم (docs/plan/44) --}}
        <fieldset class="rounded-xl border border-ink-200 p-4">
            <legend class="card-title px-1">طرق الدفع للتجّار</legend>
            <input type="hidden" name="payout_form" value="1">
            <p class="card-hint mb-3">
                ما يختار منه التاجر حين يطلب حسابه: النقد (بيد مندوب الاستلام أو يستلمه من الشركة)، والبطاقات والمحافظ
                بتفاصيلها. أزِل العلامة عمّا لا تتعامل به شركتك فلا يظهر للتاجر.
            </p>
            @php $offered = old('payout_offered', array_keys(\App\Support\PayoutMethods::offered($company))); @endphp
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                @foreach (\App\Models\Merchant::PAYOUT_METHODS as $method => $label)
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="payout_offered[]" value="{{ $method }}" @checked(in_array($method, (array) $offered, true))
                               class="rounded border-ink-300 text-[var(--brand)] focus:ring-brand-500">
                        {{ $label }}
                    </label>
                @endforeach
            </div>
            @error('payout_offered') <p class="field-error">{{ $message }}</p> @enderror
        </fieldset>

        {{-- ما تُلزِم به الشركة عند إدخال الشحنة (docs/plan/38): يسري على كل نموذج --}}
        <fieldset class="rounded-xl border border-ink-200 p-4">
            <legend class="card-title px-1">حقولٌ تُطلب عند إدخال الشحنة</legend>
            <p class="card-hint mb-3">
                الهاتف والمحافظة والمنطقة والمبلغ مطلوبةٌ دائماً. وما تختاره هنا يصير مطلوباً أيضاً: في نموذج الشحنة،
                وبوابة التاجر، والإدخال السريع، وملفّ Excel. واسم المستلم اختياريّ ما لم تختره، ويُطبع على الوصل إن كُتب.
            </p>
            @php $chosen = old('shipment_required', \App\Support\ShipmentFields::required($company)); @endphp
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                @foreach (\App\Support\ShipmentFields::CHOOSABLE as $field => $label)
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="shipment_required[]" value="{{ $field }}" @checked(in_array($field, (array) $chosen, true))
                               class="rounded border-ink-300 text-[var(--brand)] focus:ring-brand-500">
                        {{ $label }}
                    </label>
                @endforeach
            </div>
            @error('shipment_required.*') <p class="field-error">{{ $message }}</p> @enderror
        </fieldset>
    </section>

    <section class="card space-y-4 p-5">
        <div>
            <label class="field-label" for="primary_color">لون الشعار</label>
            <div class="flex items-center gap-3">
                <input id="primary_color" name="primary_color" type="color" class="h-10 w-16 cursor-pointer rounded-lg border border-ink-200"
                       value="{{ old('primary_color', $company->primary_color) }}"
                       data-css-var="--company">
                <span class="num text-sm text-ink-600">{{ old('primary_color', $company->primary_color) }}</span>
            </div>
            <p class="mt-1 text-xs text-ink-500">مربّع الشعار في أعلى كل صفحة: النظام، وبوّابة التاجر، وتطبيق المندوب، وصفحة التتبّع. يُعاين هنا قبل الحفظ.</p>
        </div>

        <button type="submit" class="btn-primary w-full">احفظ</button>
    </section>
</form>

{{-- ميزات نظامها ورسومها، واشتراكها وفواتيرها: صفحتها (docs/plan/36) --}}
@unless (auth()->user()->isBranchLimited())
    <p class="mt-5 text-sm text-ink-600">
        ميزات نظامك ورسومها الشهرية، واشتراكك وفواتيرك وكيف تدفع، في
        <a href="{{ route('billing') }}" class="font-semibold text-[var(--brand)] hover:underline">«اشتراك الشركة وفواتيرها»</a>.
    </p>
@endunless
@endsection
