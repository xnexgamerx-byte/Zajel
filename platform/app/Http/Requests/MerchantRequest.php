<?php

namespace App\Http\Requests;

use App\Models\Merchant;
use App\Models\PriceList;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MerchantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $merchant = $this->route('merchant');

        return [
            'business_name'    => ['required', 'string', 'max:200'],
            'owner_name'       => ['nullable', 'string', 'max:160'],
            'phone'            => ['required', 'string', 'regex:/^07[0-9]{9}$/'],
            'phone_alt'        => ['nullable', 'string', 'regex:/^07[0-9]{9}$/'],
            'email'            => ['nullable', 'email', 'max:160'],

            'governorate_id'   => ['nullable', 'integer', Rule::exists('governorates', 'id')],
            'city_id'          => ['nullable', 'integer'],
            'address'          => ['nullable', 'string', 'max:255'],
            'landmark'         => ['nullable', 'string', 'max:255'],

            'branch_id'        => ['nullable', 'integer'],
            'price_list_id'    => ['nullable', 'integer'],
            'settlement_cycle' => ['required', Rule::in(['daily', 'weekly', 'biweekly', 'monthly', 'on_demand'])],
            'payout_method'    => ['required', Rule::in(['cash', 'zaincash', 'asiahawala', 'fastpay', 'qi', 'fib', 'bank_transfer'])],
            'payout_account'   => ['nullable', 'string', 'max:120'],
            'status'           => ['required', Rule::in(['active', 'suspended', 'pending'])],
            'notes'            => ['nullable', 'string', 'max:500'],

            'create_login'     => ['nullable', 'boolean'],
            'password'         => ['nullable', 'string', 'min:6', 'max:72'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $merchant = $this->route('merchant');

            // الفريد داخل الشركة فقط — تاجر بالرقم نفسه عند شركة أخرى شأنها.
            $duplicate = Merchant::where('phone', $this->phone)
                ->when($merchant, fn ($q) => $q->whereKeyNot($merchant->id))
                ->exists();

            if ($duplicate) {
                $validator->errors()->add('phone', 'يوجد تاجر بهذا الرقم في شركتك.');
            }

            if ($this->price_list_id && ! PriceList::whereKey($this->price_list_id)->exists()) {
                $validator->errors()->add('price_list_id', 'قائمة التسعير غير موجودة.');
            }

            if ($this->city_id && $this->governorate_id) {
                $belongs = \App\Models\City::whereKey($this->city_id)
                    ->where('governorate_id', $this->governorate_id)
                    ->exists();

                if (! $belongs) {
                    $validator->errors()->add('city_id', 'المنطقة لا تتبع المحافظة المختارة.');
                }
            }

            if ($this->boolean('create_login') && ! $merchant && ! $this->password) {
                $validator->errors()->add('password', 'أدخل كلمة مرور لحساب دخول التاجر.');
            }
        });
    }

    public function attributes(): array
    {
        return [
            'business_name'    => 'اسم المتجر',
            'owner_name'       => 'اسم صاحب المتجر',
            'phone'            => 'الهاتف',
            'phone_alt'        => 'هاتف بديل',
            'governorate_id'   => 'المحافظة',
            'city_id'          => 'المنطقة',
            'price_list_id'    => 'قائمة التسعير',
            'settlement_cycle' => 'دورة التسوية',
            'payout_method'    => 'طريقة الدفع',
            'status'           => 'الحالة',
            'password'         => 'كلمة المرور',
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex'     => 'الهاتف يجب أن يبدأ بـ 07 ويتكوّن من 11 رقماً.',
            'phone_alt.regex' => 'الهاتف البديل يجب أن يبدأ بـ 07 ويتكوّن من 11 رقماً.',
        ];
    }
}
