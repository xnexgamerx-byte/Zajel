<?php

namespace App\Http\Requests;

use App\Models\Courier;
use App\Models\User;
use App\Support\Username;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CourierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name'                    => ['required', 'string', 'max:160'],
            'phone'                   => ['required', 'string', 'regex:/^07[0-9]{9}$/'],
            'national_id'             => ['nullable', 'string', 'max:40'],

            'type'                    => ['required', Rule::in(['delivery', 'pickup', 'both'])],
            'vehicle_type'            => ['required', Rule::in(['motorcycle', 'car', 'van', 'truck', 'on_foot'])],
            'vehicle_number'          => ['nullable', 'string', 'max:40'],
            'branch_id'               => ['nullable', 'integer'],

            'commission_per_delivery' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'commission_per_pickup'   => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'commission_per_return'   => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'cash_limit'              => ['nullable', 'integer', 'min:0'],

            'status'                  => ['required', Rule::in(['active', 'suspended', 'inactive'])],
            'zones'                   => ['nullable', 'array'],
            'zones.*'                 => ['integer'],

            'create_login'            => ['nullable', 'boolean'],
            'username'                => ['nullable', 'string', 'max:64'],
            'password'                => ['nullable', 'string', 'min:6', 'max:72'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $courier = $this->route('courier');

            $duplicate = Courier::where('phone', $this->phone)
                ->when($courier, fn ($q) => $q->whereKeyNot($courier->id))
                ->exists();

            if ($duplicate) {
                $validator->errors()->add('phone', 'يوجد مندوب بهذا الرقم في شركتك.');
            }

            if ($this->boolean('create_login') && ! $courier && ! $this->password) {
                $validator->errors()->add('password', 'أدخل كلمة مرور لحساب دخول المندوب.');
            }

            // اسمٌ مختار للحساب: صالحٌ وغير مأخوذ. وفارغاً يُدخَل برقم الهاتف
            if ($this->boolean('create_login') && ! $courier && filled($this->username)) {
                $username = Username::normalise($this->username);

                if ($username === null) {
                    $validator->errors()->add('username', Username::RULE_MESSAGE);
                } elseif (User::where('username', $username)->exists()) {
                    $validator->errors()->add('username', 'اسم المستخدم هذا لحسابٍ آخر في شركتك.');
                }
            }
        });
    }

    public function attributes(): array
    {
        return [
            'name'                    => 'الاسم',
            'phone'                   => 'الهاتف',
            'type'                    => 'نوع المندوب',
            'vehicle_type'            => 'وسيلة النقل',
            'commission_per_delivery' => 'عمولة التوصيل',
            'commission_per_pickup'   => 'عمولة الاستلام',
            'commission_per_return'   => 'عمولة الإرجاع',
            'cash_limit'              => 'سقف النقد',
            'status'                  => 'الحالة',
            'username'                => 'اسم المستخدم',
            'password'                => 'كلمة المرور',
        ];
    }

    public function messages(): array
    {
        return ['phone.regex' => 'الهاتف يجب أن يبدأ بـ 07 ويتكوّن من 11 رقماً.'];
    }
}
