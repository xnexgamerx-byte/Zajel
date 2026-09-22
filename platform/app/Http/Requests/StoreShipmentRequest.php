<?php

namespace App\Http\Requests;

use App\Models\Merchant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            // التاجر يُتحقَّق منه عبر Rule::exists غير كافٍ وحده — التحقّق الحقيقي
            // في withValidator أدناه، لأن exists لا يعرف بالشركة الحالية.
            'merchant_id'         => ['required', 'integer'],
            'recipient_name'      => ['required', 'string', 'max:160'],
            'recipient_phone'     => ['required', 'string', 'regex:/^07[0-9]{9}$/'],
            'recipient_phone_alt' => ['nullable', 'string', 'regex:/^07[0-9]{9}$/'],

            'governorate_id'      => ['required', 'integer', Rule::exists('governorates', 'id')->where('is_active', true)],
            'city_id'             => ['nullable', 'integer', Rule::exists('cities', 'id')],
            'address'             => ['required', 'string', 'max:500'],
            'landmark'            => ['required', 'string', 'min:3', 'max:255'],
            'lat'                 => ['nullable', 'numeric', 'between:-90,90'],
            'lng'                 => ['nullable', 'numeric', 'between:-180,180'],

            'description'         => ['nullable', 'string', 'max:2000'],
            'pieces_count'        => ['required', 'integer', 'min:1', 'max:255'],
            'weight_grams'        => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'is_fragile'          => ['nullable', 'boolean'],
            'allow_open'          => ['nullable', 'boolean'],
            'notes'               => ['nullable', 'string', 'max:2000'],

            'cod_amount'          => ['required', 'integer', 'min:0', 'max:100000000'],
            'delivery_fee'        => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'extra_fee'           => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'discount'            => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'fees_paid_by'        => ['required', Rule::in(['merchant', 'customer'])],
            'merchant_reference'  => ['nullable', 'string', 'max:60'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // CompanyScope مفعّل هنا: تاجر من شركة أخرى لا يُعثَر عليه أصلاً.
            if ($this->merchant_id && ! Merchant::where('id', $this->merchant_id)->exists()) {
                $validator->errors()->add('merchant_id', 'التاجر غير موجود.');
            }

            if ($this->city_id && $this->governorate_id) {
                $belongs = \App\Models\City::where('id', $this->city_id)
                    ->where('governorate_id', $this->governorate_id)
                    ->exists();

                if (! $belongs) {
                    $validator->errors()->add('city_id', 'المنطقة لا تتبع المحافظة المختارة.');
                }
            }
        });
    }

    public function attributes(): array
    {
        return [
            'merchant_id'     => 'التاجر',
            'recipient_name'  => 'اسم المستلم',
            'recipient_phone' => 'هاتف المستلم',
            'recipient_phone_alt' => 'هاتف بديل',
            'governorate_id'  => 'المحافظة',
            'city_id'         => 'المنطقة',
            'address'         => 'العنوان',
            'landmark'        => 'أقرب نقطة دالّة',
            'pieces_count'    => 'عدد القطع',
            'weight_grams'    => 'الوزن',
            'cod_amount'      => 'المبلغ المطلوب',
            'delivery_fee'    => 'أجرة التوصيل',
            'fees_paid_by'    => 'من يدفع الأجرة',
        ];
    }

    public function messages(): array
    {
        return [
            'recipient_phone.regex'     => 'رقم الهاتف يجب أن يبدأ بـ 07 ويتكوّن من 11 رقماً.',
            'recipient_phone_alt.regex' => 'الهاتف البديل يجب أن يبدأ بـ 07 ويتكوّن من 11 رقماً.',
            'landmark.required'         => 'أقرب نقطة دالّة مطلوبة — بلا رمز بريدي في العراق، هي ما يوصل المندوب.',
            'landmark.min'              => 'اكتب نقطة دالّة واضحة (مثل: مقابل جامع، قرب مول).',
        ];
    }
}
