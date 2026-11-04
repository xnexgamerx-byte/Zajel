<?php

namespace App\Http\Requests;

use App\Models\Courier;
use App\Support\LoginAccounts;
use App\Support\Username;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CourierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** موظّف فرعٍ يضيف مناديب لفرعه وحده، ولا ينقل مندوباً إلى فرعٍ آخر. */
    protected function prepareForValidation(): void
    {
        if ($this->user()?->isBranchLimited()) {
            $this->merge(['branch_id' => $this->user()->branch_id]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'phone' => ['required', 'string', 'regex:/^07[0-9]{9}$/'],
            'national_id' => ['nullable', 'string', 'max:40'],

            'type' => ['required', Rule::in(['delivery', 'pickup', 'both', 'transfer'])],
            'is_intermediary' => ['nullable', 'boolean'],
            'sees_merchant_phone' => ['nullable', 'boolean'],
            'vehicle_type' => ['required', Rule::in(['motorcycle', 'car', 'van', 'truck', 'on_foot'])],
            'vehicle_number' => ['nullable', 'string', 'max:40'],
            'branch_id' => ['nullable', 'integer'],

            'commission_per_delivery' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'commission_per_pickup' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'commission_per_return' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'cash_limit' => ['nullable', 'integer', 'min:0'],

            'parent_id' => ['nullable', 'integer'],
            'partner_centre_type' => ['nullable', Rule::in(array_keys(Courier::PARTNER_CENTRE))],
            'partner_centre_value' => ['nullable', 'integer', 'min:0', 'max:100000000'],

            'status' => ['required', Rule::in(['active', 'suspended', 'inactive'])],
            'zones' => ['nullable', 'array'],
            'zones.*' => ['integer'],

            'create_login' => ['nullable', 'boolean'],
            'username' => ['nullable', 'string', 'max:64'],
            'password' => ['nullable', 'string', 'min:6', 'max:72'],
        ];
    }

    /** الفارغ «بلا أب» و«ليس شريكاً» لا null في عمودٍ له افتراض */
    public function validated($key = null, $default = null)
    {
        $data = parent::validated($key, $default);

        if ($key === null) {
            $data['is_intermediary'] = (bool) ($data['is_intermediary'] ?? false);
            // ما لم يُعلَّم لا يراه (docs/plan/95)
            $data['sees_merchant_phone'] = (bool) ($data['sees_merchant_phone'] ?? false);
            $data['parent_id'] = filled($data['parent_id'] ?? null) ? (int) $data['parent_id'] : null;
            $data['partner_centre_type'] = $data['partner_centre_type'] ?? 'none';
            $data['partner_centre_value'] = $data['partner_centre_type'] === 'none' ? 0 : (int) ($data['partner_centre_value'] ?? 0);
        }

        return $data;
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

            // رقم المندوب لا يُشارَك (docs/plan/116): الميزة للتجّار وحدهم

            // مستوىً واحد: الأب مندوب توصيلٍ ليس فرعيّاً، ومن له فريقٌ لا يصير فرعيّاً
            if (filled($this->parent_id)) {
                $parent = Courier::delivering()->visibleTo($this->user())->find($this->parent_id);

                if (! $parent || ($courier && $parent->id === $courier->id)) {
                    $validator->errors()->add('parent_id', 'اختر مندوب توصيلٍ غيره أباً له.');
                } elseif ($parent->parent_id !== null) {
                    $validator->errors()->add('parent_id', "{$parent->name} فرعيٌّ تحت غيره — الأب لا يكون فرعيّاً.");
                } elseif ($courier && $courier->subs()->exists()) {
                    $validator->errors()->add('parent_id', 'لهذا المندوب فرعيّون تحته — انقلهم أوّلاً قبل أن يصير فرعيّاً.');
                }
            }

            if ($this->input('partner_centre_type') === 'percent' && (int) $this->input('partner_centre_value') > 100) {
                $validator->errors()->add('partner_centre_value', 'النسبة من صفر إلى مئة.');
            }

            // حسابٌ قائم: يُغيَّر اسمه إلى اسمٍ صالحٍ لا يحمله غيره، وكلمة مروره الجديدة اختياريّة
            $account = $courier?->loginAccount();

            if ($account && ($problem = LoginAccounts::usernameProblem($this->username, $account->id))) {
                $validator->errors()->add('username', $problem);
            }

            // ورقمٌ جديد للمندوب يتبعه حساب دخوله (docs/plan/116): فلا يكون رقماً لحسابٍ آخر
            if ($account && filled($this->phone) && $this->phone !== $courier->phone && ! $validator->errors()->has('phone')
                && ($problem = LoginAccounts::phoneProblem($this->phone, $account->id))) {
                $validator->errors()->add('phone', $problem);
            }

            // حسابٌ جديد عند الإضافة، أو عند التعديل لمن لا حساب له — ولا يُتخطّى بصمت: إمّا يُنشأ، وإمّا يقال لماذا
            if ($this->boolean('create_login') && ! $account) {
                if (! $this->password) {
                    $validator->errors()->add('password', 'أدخل كلمة مرور لحساب دخول المندوب.');
                }

                $phone = filled($this->phone) ? $this->phone : $courier?->phone;

                if (! $validator->errors()->has('phone') && ($problem = LoginAccounts::phoneProblem($phone))) {
                    $validator->errors()->add('phone', $problem);
                } elseif ($problem = LoginAccounts::usernameProblem($this->username)) {
                    $validator->errors()->add('username', $problem);
                } elseif (blank($this->username) && $phone && ($holder = LoginAccounts::holder('username', Username::canonical($phone)))) {
                    $validator->errors()->add('username', 'رقمه '.$phone.' اسم دخولٍ ل'.LoginAccounts::describe($holder)
                        .'. اكتب له اسم مستخدمٍ غيره.');
                }
            }
        });
    }

    public function attributes(): array
    {
        return [
            'name' => 'الاسم',
            'phone' => 'الهاتف',
            'type' => 'نوع المندوب',
            'vehicle_type' => 'وسيلة النقل',
            'commission_per_delivery' => 'عمولة التوصيل',
            'commission_per_pickup' => 'عمولة الاستلام',
            'commission_per_return' => 'عمولة الإرجاع',
            'cash_limit' => 'سقف النقد',
            'parent_id' => 'مندوب التوصيل الأب',
            'partner_centre_type' => 'الشراكة',
            'partner_centre_value' => 'حصّة المركز',
            'status' => 'الحالة',
            'username' => 'اسم المستخدم',
            'password' => 'كلمة المرور',
        ];
    }

    public function messages(): array
    {
        return ['phone.regex' => 'الهاتف يجب أن يبدأ بـ 07 ويتكوّن من 11 رقماً.'];
    }
}
