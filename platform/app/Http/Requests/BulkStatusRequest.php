<?php

namespace App\Http\Requests;

use App\Actions\Shipments\ChangeStatusInBulk;
use App\Enums\ShipmentStatus;
use App\Models\FailureReason;
use App\Services\Shipments\BulkSelection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * تحديث الحالة من القائمة: الشحنات المختارة بأرقامها، أو «الكل» ببحث القائمة
 * نفسه وعدد ما رآه الموظّف فيها — فلا يُحدَّث ما لم يرَه.
 */
class BulkStatusRequest extends FormRequest
{
    /** كل هدفٍ بصلاحيته: الإخراج مع مندوبٍ لمن يُسند، وما عداه لمن يغيّر الحالة */
    public function authorize(): bool
    {
        $to = ShipmentStatus::tryFrom((string) $this->input('status'));

        // هدفٌ لا يُحدَّث بالجملة يُرفض برسالةٍ في الحقل، لا بصفحة 403
        if ($to === null || ! array_key_exists($to->value, ChangeStatusInBulk::targets())) {
            return $this->user() !== null;
        }

        return (bool) $this->user()?->can(ChangeStatusInBulk::ability($to));
    }

    public function rules(): array
    {
        return [
            'status'            => ['required', Rule::in(array_keys(ChangeStatusInBulk::targets()))],
            ...BulkSelection::rules(),
            'courier_id'        => ['exclude_unless:status,out_for_delivery', 'required', 'integer'],
            'failure_reason_id' => ['exclude_unless:status,failed_attempt', 'required', 'integer'],
            'note'              => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('status') !== ShipmentStatus::FailedAttempt->value || ! $this->failure_reason_id) {
                return;
            }

            // كما في صفحة الشحنة: سببٌ مصنَّف من أسباب الشركة، وملاحظةٌ إن طلبها
            $reason = FailureReason::availableFor($this->user()->company_id)->whereKey($this->failure_reason_id)->first();

            if (! $reason) {
                $validator->errors()->add('failure_reason_id', 'سبب عدم التسليم غير موجود.');
            } elseif ($reason->requires_note && ! trim((string) $this->note)) {
                $validator->errors()->add('note', "السبب «{$reason->name_ar}» يتطلّب ملاحظة توضيحية.");
            }
        });
    }

    public function attributes(): array
    {
        return [
            'status'            => 'الحالة الجديدة',
            'shipment_ids'      => 'الشحنات',
            'courier_id'        => 'المندوب',
            'failure_reason_id' => 'سبب عدم التسليم',
            'note'              => 'الملاحظة',
        ];
    }

    public function messages(): array
    {
        return [
            'status.required'            => 'اختر الحالة الجديدة.',
            'status.in'                  => 'هذه الحالة لا تُحدَّث بالجملة — غيّرها من صفحة الشحنة.',
            ...BulkSelection::messages(),
            'courier_id.required'        => 'اختر المندوب.',
            'failure_reason_id.required' => 'اختر سبب عدم التسليم.',
        ];
    }
}
