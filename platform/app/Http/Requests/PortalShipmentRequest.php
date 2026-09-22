<?php

namespace App\Http\Requests;

/**
 * التاجر يُنشئ شحنة لنفسه فقط، ولا يسعّرها.
 *
 * merchant_id لا يأتي من النموذج بل من حسابه — فلا يستطيع إرسال
 * شحنة باسم تاجر آخر مهما عدّل في الطلب. والأجور تُحسب من تسعيرة
 * الشركة لا من إدخاله.
 */
class PortalShipmentRequest extends StoreShipmentRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'merchant_id'  => $this->user()->merchant_id,
            'delivery_fee' => null,
            'extra_fee'    => 0,
            'discount'     => 0,
        ]);
    }

    public function rules(): array
    {
        $rules = parent::rules();

        // التسعير ليس من حقّه: تُترك الحقول للحساب الآلي
        unset($rules['delivery_fee'], $rules['extra_fee'], $rules['discount']);

        return $rules;
    }
}
