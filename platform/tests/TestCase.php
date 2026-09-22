<?php

namespace Tests;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Governorate;
use App\Models\Merchant;
use App\Models\PriceList;
use App\Models\PriceListRule;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * رقم عراقي الشكل ومستقرّ من بذرة نصّية.
     *
     * md5 يعطي أحرفاً سداسية عشرية فترفضها قواعد التحقّق — وهو ما جعل
     * اختبار شاشة المستخدمين يفشل على حساب أنشأه المساعد نفسه.
     */
    protected function phoneFrom(string $seed, string $prefix = '0770'): string
    {
        return $prefix.substr(str_pad((string) crc32($seed), 7, '0', STR_PAD_LEFT), -7);
    }

    protected function tearDown(): void
    {
        Tenancy::forget();
        parent::tearDown();
    }

    /** ينشئ شركة كاملة الأركان: فرع، تسعيرة، تاجر، صاحب حساب. */
    protected function makeCompany(string $slug = 'acme', string $name = 'شركة تجريبية'): Company
    {
        return Tenancy::runAsPlatform(fn () => Company::create([
            'slug' => $slug, 'name' => $name, 'status' => 'active',
        ]));
    }

    protected function seedReference(): void
    {
        $this->seed(\Database\Seeders\GovernorateSeeder::class);
        $this->seed(\Database\Seeders\FailureReasonSeeder::class);
    }

    protected function makeMerchant(Company $company, string $code = 'M0001'): Merchant
    {
        return Tenancy::runFor($company, function () use ($company, $code) {
            $branch = Branch::firstOrCreate(
                ['code' => 'B1'],
                ['name' => 'الفرع الرئيسي', 'is_main' => true],
            );

            $list = PriceList::firstOrCreate(
                ['name' => 'افتراضية'],
                ['is_default' => true, 'is_active' => true],
            );

            PriceListRule::firstOrCreate(
                ['price_list_id' => $list->id, 'weight_from_grams' => 0, 'weight_to_grams' => 5000],
                ['delivery_fee' => 5000, 'return_fee' => 2500, 'extra_kg_fee' => 1000, 'is_active' => true],
            );

            return Merchant::firstOrCreate(
                ['code' => $code],
                [
                    'business_name' => 'متجر '.$code,
                    'phone' => $this->phoneFrom($company->slug.$code, '0771'),
                    'branch_id' => $branch->id, 'price_list_id' => $list->id, 'status' => 'active',
                ],
            );
        });
    }

    protected function makeUser(Company $company, UserRole $role = UserRole::CompanyOwner): User
    {
        return Tenancy::runFor($company, fn () => User::firstOrCreate(
            ['phone' => $this->phoneFrom($company->slug.$role->value)],
            ['name' => 'مستخدم', 'password' => 'password', 'role' => $role, 'is_active' => true],
        ));
    }

    protected function baghdad(): Governorate
    {
        return Governorate::where('code', 'BGD')->firstOrFail();
    }
}
