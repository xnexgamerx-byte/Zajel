<?php

namespace App\Http\Requests;

use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isPlatformUser() ?? false;
    }

    public function rules(): array
    {
        return [
            'name'                 => ['required', 'string', 'max:160'],
            'name_en'              => ['nullable', 'string', 'max:160'],
            // النطاق الفرعي: حروف لاتينية صغيرة وأرقام وشرطة، ولا يبدأ أو ينتهي بشرطة
            'slug'                 => ['required', 'string', 'min:2', 'max:40',
                                       'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
                                       Rule::unique('companies', 'slug'),
                                       Rule::notIn(['www', 'admin', 'api', 'app', 'mail', 'track'])],
            'primary_color'        => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'phone'                => ['nullable', 'string', 'regex:/^07[0-9]{9}$/'],
            'email'                => ['nullable', 'email', 'max:160'],
            'governorate_id'       => ['nullable', 'integer', Rule::exists('governorates', 'id')],
            'address'              => ['nullable', 'string', 'max:255'],
            'status'               => ['required', Rule::in(['trial', 'active'])],

            'branch_name'          => ['nullable', 'string', 'max:160'],
            'default_delivery_fee' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'default_return_fee'   => ['nullable', 'integer', 'min:0', 'max:10000000'],

            'owner_name'           => ['required', 'string', 'max:160'],
            'owner_phone'          => ['required', 'string', 'regex:/^07[0-9]{9}$/'],
            'owner_email'          => ['nullable', 'email', 'max:160'],
            'owner_password'       => ['required', 'string', 'min:6', 'max:72'],

            'plan_id'              => ['nullable', 'integer', Rule::exists('plans', 'id')],
            'billing_cycle'        => ['nullable', Rule::in(['monthly', 'yearly'])],
        ];
    }

    public function attributes(): array
    {
        return [
            'name'           => 'اسم الشركة',
            'slug'           => 'النطاق الفرعي',
            'owner_name'     => 'اسم صاحب الشركة',
            'owner_phone'    => 'هاتف صاحب الشركة',
            'owner_password' => 'كلمة المرور',
            'plan_id'        => 'الباقة',
            'status'         => 'الحالة',
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex'        => 'النطاق الفرعي: حروف إنجليزية صغيرة وأرقام وشرطة فقط (مثل barq أو al-zaeem).',
            'slug.unique'       => 'هذا النطاق محجوز لشركة أخرى.',
            'slug.not_in'       => 'هذا النطاق محجوز للمنصّة.',
            'phone.regex'       => 'الهاتف يجب أن يبدأ بـ 07 ويتكوّن من 11 رقماً.',
            'owner_phone.regex' => 'هاتف صاحب الشركة يجب أن يبدأ بـ 07 ويتكوّن من 11 رقماً.',
        ];
    }
}
