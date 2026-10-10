@extends('layouts.app')
@section('title', 'تعديل الأجور والطلبية — '.$shipment->number)

@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="page-title">تعديل الأجور والطلبية <span class="num text-ink-500">{{ $shipment->number }}</span></h1>
        <p class="mt-1 text-sm text-ink-500">
            بصلاحيةٍ خاصّة، أيّاً كانت حال الشحنة. وما قُيِّد مالها يُقيَّد فرقه في الحساب بسببك، لا يتغيّر رقمٌ بصمت.
        </p>
    </div>
    <div class="flex items-center gap-2">
        <x-status-badge :status="$shipment->status" :shipment="$shipment" />
        <a href="{{ route('shipments.show', $shipment) }}" class="btn-ghost">رجوع للشحنة</a>
    </div>
</div>

@if ($shipment->merchant_settlement_id || $shipment->courier_settlement_id)
    <div class="alert alert-warn mb-5 text-sm" role="status">
        <x-icon name="alert" class="size-5 shrink-0"/>
        <p>
            @if ($shipment->merchant_settlement_id) دخلت كشف تاجرٍ أُقفِل: أجرتاه (التوصيل والراجع) لا تتغيّران هنا. @endif
            @if ($shipment->courier_settlement_id) دخلت كشف مندوبٍ أُقفِل: أجرة المندوب لا تتغيّر هنا. @endif
            احذف الكشف في مهلته أوّلاً إن كان خطأً. بيانات الطلبية تُصحَّح كالعادة.
        </p>
    </div>
@endif

<form method="POST" action="{{ route('shipments.override.update', $shipment) }}" class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    @csrf
    @method('PUT')

    <section class="card space-y-4 p-5 lg:col-span-2">
        <h2 class="card-title">الطلبية</h2>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label class="field-label" for="recipient_name">اسم المستلم</label>
                <input id="recipient_name" name="recipient_name" type="text" maxlength="160" class="field-input"
                       value="{{ old('recipient_name', $shipment->recipient_name === \App\Models\Shipment::UNNAMED_RECIPIENT ? '' : $shipment->recipient_name) }}">
            </div>
            <div>
                <label class="field-label" for="recipient_phone">هاتف المستلم</label>
                <input id="recipient_phone" name="recipient_phone" type="tel" maxlength="11" required class="field-input num"
                       value="{{ old('recipient_phone', $shipment->recipient_phone) }}">
                @error('recipient_phone') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="field-label" for="recipient_phone_alt">هاتف بديل</label>
                <input id="recipient_phone_alt" name="recipient_phone_alt" type="tel" maxlength="11" class="field-input num"
                       value="{{ old('recipient_phone_alt', $shipment->recipient_phone_alt) }}">
                @error('recipient_phone_alt') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="field-label" for="merchant_reference">رقم طلب التاجر</label>
                <input id="merchant_reference" name="merchant_reference" type="text" maxlength="100" class="field-input"
                       value="{{ old('merchant_reference', $shipment->merchant_reference) }}">
            </div>
            <div class="sm:col-span-2">
                <label class="field-label" for="landmark">أقرب نقطة دالّة</label>
                <input id="landmark" name="landmark" type="text" maxlength="255" class="field-input"
                       value="{{ old('landmark', $shipment->landmark) }}">
            </div>
            @if (filled($shipment->address))
                <div class="sm:col-span-2">
                    <label class="field-label" for="address">العنوان</label>
                    <input id="address" name="address" type="text" maxlength="500" class="field-input"
                           value="{{ old('address', $shipment->address) }}">
                </div>
            @endif
            <div>
                <label class="field-label" for="description">وصف المحتوى</label>
                <input id="description" name="description" type="text" maxlength="500" class="field-input"
                       value="{{ old('description', $shipment->description) }}">
            </div>
            <div>
                <label class="field-label" for="pieces_count">عدد القطع</label>
                <input id="pieces_count" name="pieces_count" type="number" min="1" max="1000" class="field-input num"
                       value="{{ old('pieces_count', $shipment->pieces_count) }}">
            </div>
            <div class="sm:col-span-2">
                <label class="field-label" for="notes">ملاحظات للمندوب</label>
                <textarea id="notes" name="notes" rows="2" maxlength="500" class="field-input">{{ old('notes', $shipment->notes) }}</textarea>
            </div>
        </div>
        <p class="text-xs text-ink-500">
            المحافظة من «تعديل البيانات» قبل المخزن. والمبلغ من «الأجور» هنا: يتحدّث على الوصل وكشف المندوب وحساب التاجر معاً.
        </p>
    </section>

    <div class="space-y-5">
        <section class="card space-y-4 p-5">
            <h2 class="card-title">المبلغ والأجور</h2>
            {{-- كُتب خطأً (25 بدل 25,000): يُصحَّح هنا ويتبعه كل ما بُني عليه (docs/plan/61) --}}
            <div>
                <label class="field-label" for="cod_amount">مبلغ الوصل</label>
                <input id="cod_amount" name="cod_amount" type="number" min="0" step="1" class="field-input num"
                       value="{{ old('cod_amount', $shipment->cod_amount) }}" @disabled($shipment->merchant_settlement_id)>
                @error('cod_amount') <p class="field-error">{{ $message }}</p> @enderror
                <p class="field-hint">
                    {{ $shipment->wasDelivered() ? 'سُلِّمت: يتبعه المحصَّل وحساب التاجر وعهدة المندوب بفرقه.' : 'يُعاد حساب الأجور ومستحقّ التاجر منه.' }}
                    والتاجر يُشعَر بالمبلغ الجديد.
                </p>
            </div>
            <div>
                <label class="field-label" for="delivery_fee">أجرة التوصيل على التاجر</label>
                <input id="delivery_fee" name="delivery_fee" type="number" min="0" step="1" class="field-input num"
                       value="{{ old('delivery_fee', $shipment->delivery_fee) }}" @disabled($shipment->merchant_settlement_id)>
                @error('delivery_fee') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="field-label" for="return_fee">أجرة الراجع</label>
                <input id="return_fee" name="return_fee" type="number" min="0" step="1" class="field-input num"
                       value="{{ old('return_fee', $shipment->return_fee) }}" @disabled($shipment->merchant_settlement_id)>
            </div>
            <div>
                <label class="field-label" for="courier_commission">أجرة المندوب {{ $shipment->deliveryCourier ? '('.$shipment->deliveryCourier->name.')' : '' }}</label>
                <input id="courier_commission" name="courier_commission" type="number" min="0" step="1" class="field-input num"
                       value="{{ old('courier_commission', $shipment->courier_commission) }}" @disabled($shipment->courier_settlement_id)>
                @error('courier_commission') <p class="field-error">{{ $message }}</p> @enderror
                <p class="field-hint">
                    {{ $commissionPosted ? 'قُيِّدت عمولته: يُقيَّد الفرق في حسابه.' : 'تُثبَّت للشحنة فلا يغيّرها التسليم.' }}
                </p>
            </div>
            <dl class="space-y-1 rounded-xl bg-ink-50 p-3 text-xs text-ink-600">
                <div class="flex justify-between"><dt>المبلغ</dt><dd class="num">{{ number_format($shipment->collected_amount ?? $shipment->cod_amount) }}</dd></div>
                <div class="flex justify-between"><dt>مجموع الأجور</dt><dd class="num">{{ number_format($shipment->total_fees) }}</dd></div>
                <div class="flex justify-between font-semibold"><dt>مستحقّ التاجر</dt><dd class="num">{{ number_format($shipment->merchant_due) }}</dd></div>
                <div class="pt-1">{{ $merchantPosted ? 'قُيِّد مالها: فرق مستحقّ التاجر قيدٌ في حسابه.' : 'لم يُقيَّد مالها بعد: يُحسب المستحقّ من جديد.' }}</div>
            </dl>
        </section>

        <section class="card space-y-3 p-5">
            <label class="field-label" for="reason">سبب التعديل</label>
            <input id="reason" name="reason" type="text" maxlength="255" required class="field-input"
                   value="{{ old('reason') }}" placeholder="مثلاً: منطقة بعيدة، اتّفاقٌ مع التاجر">
            @error('reason') <p class="field-error">{{ $message }}</p> @enderror
            <button type="submit" class="btn-primary w-full">احفظ التعديل</button>
        </section>
    </div>
</form>
@endsection
