<?php

namespace App\Http\Requests;

/**
 * التاجر يُنشئ شحنة لنفسه فقط، ولا يسعّرها.
 *
 * merchant_id لا يأتي من النموذج بل من حسابه — فلا يستطيع إرسال
 * شحنة باسم تاجر آخر مهما عدّل في الطلب. والأجور تُحسب من تسعيرة
 * الشركة لا من إدخاله.
 *
 * ونموذجه حقول طلبه وحدها (docs/plan/28): الاسم والرقم والعنوان والسعر والعدد
 * أساسها، ومعها الهاتف الثانوي ونوع البضاعة وحجم الطلب ونوعه والملاحظات ورقم
 * الوصل المطبوع. وما سواها لا يُقبل منه ولو أُرسل.
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

        // التسعير ليس من حقّه: تُترك الحقول للحساب الآلي. والأجرة عليه، ومقدّماً أو لا
        // كما في حسابه مع الشركة — لا يختار هو
        unset($rules['delivery_fee'], $rules['extra_fee'], $rules['discount'],
              $rules['fees_paid_by'], $rules['fee_prepaid']);

        // ليست في نموذجه: تبقى لموظّف الشركة من صفحة الشحنة
        unset($rules['merchant_reference'], $rules['weight_grams'], $rules['is_fragile'], $rules['allow_open']);

        // الاسم من أساسيّات طلبه
        $rules['recipient_name'] = ['required', 'string', 'max:160'];

        return $rules;
    }

    public function attributes(): array
    {
        return [
            ...parent::attributes(),
            'recipient_name'      => 'اسم الزبون',
            'recipient_phone'     => 'رقم الهاتف الأساسي',
            'recipient_phone_alt' => 'رقم الهاتف الثانوي',
            'description'         => 'نوع البضاعة',
            'cod_amount'          => 'السعر مع التوصيل',
            'waybill'             => 'رقم الوصل المطبوع',
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'recipient_name.required'   => 'اكتب اسم الزبون.',
            'recipient_phone_alt.regex' => 'الهاتف الثانوي يجب أن يبدأ بـ 07 ويتكوّن من 11 رقماً.',
            'cod_amount.required'       => 'اكتب السعر مع التوصيل — 0 إن كان الزبون دفع لك مسبقاً.',
        ];
    }
}
