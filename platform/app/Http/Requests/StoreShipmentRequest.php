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
            // الاسم لا يُلزَم: يُحفظ «الزبون» إن تُرك (Shipment::UNNAMED_RECIPIENT)
            'recipient_name'      => ['nullable', 'string', 'max:160'],
            'recipient_phone'     => ['required', 'string', 'regex:/^07[0-9]{9}$/'],
            'recipient_phone_alt' => ['nullable', 'string', 'regex:/^07[0-9]{9}$/'],

            'governorate_id'      => ['required', 'integer', Rule::exists('governorates', 'id')->where('is_active', true)],
            // المحافظة والمنطقة والهاتف والمبلغ: هذا ما لا تخرج شحنةٌ بغيره. والمنطقة
            // تُلزَم ما دامت للمحافظة مناطق يُختار منها
            'city_id'             => [Rule::requiredIf(fn () => $this->governorateHasAreas()), 'nullable', 'integer', \App\Models\City::existsRule()],
            // العنوان المفصّل لم يعد في النموذج: يبقى لما كُتب قبل ذلك وللملفّات القديمة
            'address'             => ['nullable', 'string', 'max:500'],
            // أقرب نقطة دالّة تساعد المندوب ولا تُلزِم: حرفٌ واحد أو لا شيء
            'landmark'            => ['nullable', 'string', 'max:255'],
            'lat'                 => ['nullable', 'numeric', 'between:-90,90'],
            'lng'                 => ['nullable', 'numeric', 'between:-180,180'],

            'description'         => ['nullable', 'string', 'max:2000'],
            'pieces_count'        => ['required', 'integer', 'min:1', 'max:255'],
            'weight_grams'        => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'is_fragile'          => ['nullable', 'boolean'],
            'allow_open'          => ['nullable', 'boolean'],
            'notes'               => ['nullable', 'string', 'max:2000'],
            // «نوع الطلب» و«حجم الطلب»: الفارغ طلبٌ جديد بحجمٍ عادي، وفي التعديل يبقى ما كان
            'type'                => ['nullable', Rule::in(array_keys(\App\Models\Shipment::TYPES))],
            'size'                => ['nullable', Rule::in(array_keys(\App\Models\Shipment::SIZES))],

            'cod_amount'          => ['required', 'integer', 'min:0', 'max:100000000'],
            'delivery_fee'        => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'extra_fee'           => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'discount'            => ['nullable', 'integer', 'min:0', 'max:10000000'],
            // ليس في نموذج الشحنة: الأجرة على التاجر (CreateShipment)، والتعديل يُبقي ما كان
            'fees_paid_by'        => ['nullable', Rule::in(['merchant', 'customer'])],
            // فارغٌ: كما في حساب التاجر (يُحاسَب مقدّماً أو لا)
            'fee_prepaid'         => ['nullable', Rule::in(['0', '1'])],
            // رقم الوصل المطبوع مسبقاً إن أُدخلت منه (CreateFromWaybill يتحقّق منه)
            'waybill'             => ['nullable', 'string', 'max:20'],
            'merchant_reference'  => ['nullable', 'string', 'max:60'],
        ];
    }

    /** محافظةٌ لها مناطق مفعّلة يُختار منها؛ وما لا مناطق له تكفي محافظته */
    protected function governorateHasAreas(): bool
    {
        return filled($this->governorate_id) && is_numeric($this->governorate_id)
            && \App\Models\City::where('governorate_id', (int) $this->governorate_id)->where('is_active', true)->exists();
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // CompanyScope مفعّل هنا: تاجر من شركة أخرى لا يُعثَر عليه أصلاً.
            // وتاجرٌ من فرع الموظّف إن كان مقيَّداً بفرع
            if ($this->merchant_id && ! Merchant::visibleTo($this->user())->where('id', $this->merchant_id)->exists()) {
                $validator->errors()->add('merchant_id', 'التاجر غير موجود.');
            }

            // محافظةٌ أطفأتها الشركة في «إعدادات المحافظات» لا يُشحَن إليها — وشحنةٌ
            // قائمةٌ إليها تبقى تُصحَّح بياناتها ما دامت وجهتها لم تتغيّر
            $unchanged = ($current = $this->route('shipment')) instanceof \App\Models\Shipment
                && (int) $current->governorate_id === (int) $this->governorate_id;

            if ($this->governorate_id && ! $unchanged && ! \App\Models\Governorate::offered()->whereKey($this->governorate_id)->exists()) {
                $validator->errors()->add('governorate_id', 'شركتك لا تشحن إلى هذه المحافظة الآن.');
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
            'type'            => 'نوع الطلب',
            'size'            => 'حجم الطلب',
            'weight_grams'    => 'الوزن',
            'cod_amount'      => 'المبلغ المطلوب',
            'delivery_fee'    => 'أجرة التوصيل',
            'fees_paid_by'    => 'من يدفع الأجرة',
            'fee_prepaid'     => 'أجرة التوصيل',
        ];
    }

    public function messages(): array
    {
        return [
            'recipient_phone.regex'     => 'رقم الهاتف يجب أن يبدأ بـ 07 ويتكوّن من 11 رقماً.',
            'recipient_phone_alt.regex' => 'الهاتف البديل يجب أن يبدأ بـ 07 ويتكوّن من 11 رقماً.',
            'city_id.required'          => 'اختر المنطقة.',
            'cod_amount.required'       => 'اكتب المبلغ المطلوب من الزبون — 0 إن كان مدفوعاً مسبقاً.',
        ];
    }
}
