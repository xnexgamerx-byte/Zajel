<?php

namespace App\Http\Requests;

use App\Enums\ShipmentStatus;
use App\Models\Courier;
use App\Models\FailureReason;
use App\Models\Hub;
use App\Models\Shipment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'status'            => ['required', Rule::enum(ShipmentStatus::class)],
            'courier_id'        => ['nullable', 'integer'],
            'hub_id'            => ['nullable', 'integer'],
            'failure_reason_id' => ['nullable', 'integer'],
            'collected_amount'  => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'note'              => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            /** @var Shipment $shipment */
            $shipment = $this->route('shipment');
            $to = ShipmentStatus::tryFrom((string) $this->input('status'));

            if (! $to) {
                return;
            }

            // الانتقال يُفحَص هنا أيضاً، لا في الخدمة وحدها: الرسالة تصل
            // للمستخدم كخطأ حقل لا كصفحة خطأ.
            if (! $shipment->status->canMoveTo($to)) {
                $validator->errors()->add(
                    'status',
                    "لا يمكن الانتقال من «{$shipment->status->label()}» إلى «{$to->label()}».",
                );
            }

            // الاستعلامات هنا مفلترة بالشركة، فمندوب شركة أخرى غير موجود أصلاً.
            if ($this->courier_id && ! Courier::whereKey($this->courier_id)->exists()) {
                $validator->errors()->add('courier_id', 'المندوب غير موجود.');
            }

            if ($this->hub_id && ! Hub::whereKey($this->hub_id)->exists()) {
                $validator->errors()->add('hub_id', 'المركز غير موجود.');
            }

            if ($to === ShipmentStatus::OutForDelivery && ! $this->courier_id && ! $shipment->delivery_courier_id) {
                $validator->errors()->add('courier_id', 'اختر المندوب قبل إخراج الشحنة للتوصيل.');
            }

            if ($to === ShipmentStatus::FailedAttempt) {
                $reason = $this->failure_reason_id
                    ? FailureReason::availableFor($this->user()->company_id)->whereKey($this->failure_reason_id)->first()
                    : null;

                if (! $reason) {
                    $validator->errors()->add('failure_reason_id', 'سبب الفشل مطلوب — بلا سبب مصنَّف لا تقرير ولا محاسبة.');
                } elseif ($reason->requires_note && ! trim((string) $this->note)) {
                    $validator->errors()->add('note', "السبب «{$reason->name_ar}» يتطلّب ملاحظة توضيحية.");
                }
            }

            // تسليم جزئي بلا مبلغ = رقم مخترع في حساب التاجر
            if ($to === ShipmentStatus::PartiallyDelivered && $this->input('collected_amount') === null) {
                $validator->errors()->add('collected_amount', 'أدخل المبلغ المحصَّل فعلاً في التسليم الجزئي.');
            }

            if ($this->input('collected_amount') !== null
                && (int) $this->input('collected_amount') > $shipment->cod_amount) {
                $validator->errors()->add('collected_amount', 'المبلغ المحصَّل أكبر من المطلوب من الزبون.');
            }
        });
    }

    public function attributes(): array
    {
        return [
            'status'            => 'الحالة',
            'courier_id'        => 'المندوب',
            'hub_id'            => 'المركز',
            'failure_reason_id' => 'سبب الفشل',
            'collected_amount'  => 'المبلغ المحصَّل',
            'note'              => 'الملاحظة',
        ];
    }
}
