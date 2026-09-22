<?php

namespace App\Actions\Platform;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\Company;
use App\Models\Hub;
use App\Models\Plan;
use App\Models\PriceList;
use App\Models\PriceListRule;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;

/**
 * تسجيل شركة جديدة على المنصّة.
 *
 * لا يكفي إنشاء صفّ في companies: شركة بلا فرع ولا تسعيرة ولا حساب
 * صاحب هي نظام لا يفتح. هذا الإجراء يسلّم نظاماً يعمل من أول دخول.
 */
class RegisterCompany
{
    public function handle(array $data, ?User $actor = null): Company
    {
        return DB::transaction(function () use ($data, $actor) {
            $company = Tenancy::runAsPlatform(fn () => Company::create([
                'slug'           => $data['slug'],
                'name'           => $data['name'],
                'name_en'        => $data['name_en'] ?? null,
                'primary_color'  => $data['primary_color'] ?? '#0d9488',
                'phone'          => $data['phone'] ?? null,
                'email'          => $data['email'] ?? null,
                'governorate_id' => $data['governorate_id'] ?? null,
                'address'        => $data['address'] ?? null,
                'status'         => $data['status'] ?? 'trial',
                'trial_ends_at'  => ($data['status'] ?? 'trial') === 'trial' ? now()->addDays(14) : null,
            ]));

            Tenancy::runFor($company, function (Company $company) use ($data) {
                $branch = Branch::create([
                    'code'           => 'MAIN',
                    'name'           => $data['branch_name'] ?? 'الفرع الرئيسي',
                    'governorate_id' => $data['governorate_id'] ?? null,
                    'phone'          => $data['phone'] ?? null,
                    'is_main'        => true,
                    'is_active'      => true,
                ]);

                // قاصة رئيسية من أول يوم: بلا صندوق لا يُسجَّل نقد داخل
                // ولا مصروف خارج، ويبقى «كم في الدرج» بلا جواب.
                CashBox::create([
                    'branch_id' => $branch->id,
                    'code'      => 'MAIN',
                    'name'      => 'القاصة الرئيسية',
                    'type'      => 'main',
                    'balance'   => 0,
                    'is_active' => true,
                ]);

                Hub::create([
                    'code'           => 'HUB-MAIN',
                    'name'           => 'مركز الفرز الرئيسي',
                    'type'           => 'main',
                    'branch_id'      => $branch->id,
                    'governorate_id' => $data['governorate_id'] ?? null,
                ]);

                // تسعيرة افتراضية بقاعدة واحدة تشمل كل العراق: النظام
                // يسعّر من أول شحنة بدل أن يُرجع صفراً حتى تُضبَط التسعيرة.
                $priceList = PriceList::create([
                    'name'       => 'التسعيرة الافتراضية',
                    'is_default' => true,
                    'is_active'  => true,
                ]);

                PriceListRule::create([
                    'price_list_id'     => $priceList->id,
                    'weight_from_grams' => 0,
                    'weight_to_grams'   => 5000,
                    'delivery_fee'      => (int) ($data['default_delivery_fee'] ?? 5000),
                    'return_fee'        => (int) ($data['default_return_fee'] ?? 2500),
                    'extra_kg_fee'      => 1000,
                    'is_active'         => true,
                ]);

                User::create([
                    'name'      => $data['owner_name'],
                    'phone'     => $data['owner_phone'],
                    'email'     => $data['owner_email'] ?? null,
                    'password'  => $data['owner_password'],
                    'role'      => UserRole::CompanyOwner,
                    'branch_id' => $branch->id,
                    'is_active' => true,
                ]);
            });

            if (! empty($data['plan_id'])) {
                $this->subscribe($company, Plan::findOrFail($data['plan_id']), $data['billing_cycle'] ?? 'monthly');
            }

            AuditLog::create([
                'company_id'     => $company->id,
                'user_id'        => $actor?->id,
                'user_name'      => $actor?->name,
                'action'         => 'company_registered',
                'auditable_type' => Company::class,
                'auditable_id'   => $company->id,
                'new_values'     => ['slug' => $company->slug, 'name' => $company->name],
                'ip'             => request()->ip(),
            ]);

            return $company->refresh();
        });
    }

    /** الأسعار تُجمَّد في الاشتراك: تعديل الباقة لاحقاً لا يمسّ عقداً قائماً. */
    public function subscribe(Company $company, Plan $plan, string $cycle = 'monthly'): Subscription
    {
        return Tenancy::runFor($company, fn () => Subscription::create([
            'plan_id'                 => $plan->id,
            'status'                  => $company->status === 'trial' ? 'trialing' : 'active',
            'billing_cycle'           => $cycle,
            'price'                   => $cycle === 'yearly' ? $plan->price_yearly : $plan->price_monthly,
            'commission_per_shipment' => $plan->commission_per_shipment,
            'commission_percent'      => $plan->commission_percent,
            'starts_at'               => now()->toDateString(),
            'ends_at'                 => $cycle === 'yearly'
                ? now()->addYear()->toDateString()
                : now()->addMonth()->toDateString(),
            'auto_renew'              => true,
        ]));
    }
}
