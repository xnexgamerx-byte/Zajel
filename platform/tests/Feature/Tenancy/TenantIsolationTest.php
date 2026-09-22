<?php

namespace Tests\Feature\Tenancy;

use App\Actions\Shipments\CreateShipment;
use App\Exceptions\MissingTenantContextException;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * هذه الاختبارات هي خط الدفاع الأول للنظام.
 *
 * تسريب شحنة من شركة إلى شركة منافسة تحت السقف نفسه ليس خطأً برمجياً
 * يُصلَح لاحقاً — هو نهاية المنصّة. لذلك يفشل البناء هنا، لا في الإنتاج.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function twoCompaniesWithShipments(): array
    {
        $this->seedReference();

        $a = $this->makeCompany('zajel', 'الزاجل');
        $b = $this->makeCompany('barq', 'البرق');

        foreach ([$a, $b] as $company) {
            $merchant = $this->makeMerchant($company);
            $actor = $this->makeUser($company);

            Tenancy::runFor($company, fn () => app(CreateShipment::class)->handle([
                'merchant_id'     => $merchant->id,
                'recipient_name'  => 'زبون '.$company->slug,
                'recipient_phone' => '0780'.substr(md5($company->slug), 0, 7),
                'governorate_id'  => $this->baghdad()->id,
                'address'         => 'بغداد',
                'landmark'        => 'قرب الجامع',
                'cod_amount'      => 50_000,
            ], $actor));
        }

        return [$a, $b];
    }

    public function test_a_company_only_sees_its_own_shipments(): void
    {
        [$a, $b] = $this->twoCompaniesWithShipments();

        Tenancy::runFor($a, function () use ($a) {
            $this->assertSame(1, Shipment::count());
            $this->assertSame($a->id, Shipment::first()->company_id);
        });

        Tenancy::runFor($b, function () use ($b) {
            $this->assertSame(1, Shipment::count());
            $this->assertSame($b->id, Shipment::first()->company_id);
        });

        Tenancy::runAsPlatform(fn () => $this->assertSame(2, Shipment::count()));
    }

    public function test_a_company_cannot_fetch_another_companys_shipment_by_id(): void
    {
        [$a, $b] = $this->twoCompaniesWithShipments();

        $foreignId = Tenancy::runFor($b, fn () => Shipment::first()->id);

        Tenancy::runFor($a, function () use ($foreignId) {
            $this->assertNull(Shipment::find($foreignId));
        });
    }

    public function test_querying_without_tenant_context_throws(): void
    {
        $this->expectException(MissingTenantContextException::class);

        Shipment::count();
    }

    public function test_company_id_is_filled_automatically_on_create(): void
    {
        $this->seedReference();
        $company = $this->makeCompany();

        $merchant = Tenancy::runFor($company, fn () => Merchant::create([
            'code' => 'X1', 'business_name' => 'متجر', 'phone' => '07710000001',
        ]));

        $this->assertSame($company->id, $merchant->company_id);
    }

    public function test_company_id_cannot_be_changed_after_creation(): void
    {
        $this->seedReference();
        $a = $this->makeCompany('a', 'أ');
        $b = $this->makeCompany('b', 'ب');

        $merchant = Tenancy::runFor($a, fn () => Merchant::create([
            'code' => 'X1', 'business_name' => 'متجر', 'phone' => '07710000002',
        ]));

        Tenancy::runFor($a, function () use ($merchant, $b, $a) {
            $merchant->company_id = $b->id;
            $merchant->save();

            $this->assertSame($a->id, $merchant->fresh()->company_id);
        });
    }

    /**
     * فحص بنيوي: كل نموذج يحمل عمود company_id يجب أن يستعمل
     * BelongsToCompany. إضافة نموذج جديد ونسيان السطر = فشل البناء.
     */
    public function test_every_company_scoped_model_uses_the_trait(): void
    {
        $exempt = [
            \App\Models\Company::class,       // هو الجذر نفسه
            \App\Models\FailureReason::class, // company_id = null يعني سبب عام
            \App\Models\Setting::class,       // company_id = null يعني إعداد عام
            \App\Models\AuditLog::class,      // يُقرأ في وضع النواة للتدقيق
        ];

        $missing = [];

        foreach (File::files(app_path('Models')) as $file) {
            $class = 'App\\Models\\'.$file->getFilenameWithoutExtension();

            if (! class_exists($class) || in_array($class, $exempt, true)) {
                continue;
            }

            $model = new $class;

            if (! $model instanceof Model) {
                continue;
            }

            $hasColumn = $model->getConnection()
                ->getSchemaBuilder()
                ->hasColumn($model->getTable(), 'company_id');

            $usesTrait = in_array(
                \App\Models\Concerns\BelongsToCompany::class,
                class_uses_recursive($class),
                true,
            );

            if ($hasColumn && ! $usesTrait) {
                $missing[] = $class;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'نماذج تحمل company_id بلا BelongsToCompany — تسريب محتمل: '.implode(', ', $missing),
        );
    }

    /**
     * نطاق فرعي مجهول بينما في المتصفّح جلسة مفتوحة كان يُنتج 500 لا 404:
     * حارس المصادقة يستعلم عن المستخدم أثناء حفظ الجلسة، أي بعد أن
     * يكون الوسيط قد أنهى عمله، فيرمي النطاق هناك.
     */
    public function test_an_unknown_subdomain_is_a_not_found_even_with_a_live_session(): void
    {
        $this->seedReference();
        $company = $this->makeCompany('zajel', 'الزاجل');

        $this->actingAs($this->makeUser($company))
            ->get('http://nope.'.config('zajel.tenant_domain').'/shipments')
            ->assertNotFound();
    }

    public function test_querying_users_without_context_returns_nothing_rather_than_everything(): void
    {
        $this->seedReference();
        $a = $this->makeCompany('a', 'أ');
        $b = $this->makeCompany('b', 'ب');

        $this->makeUser($a);
        $this->makeUser($b);

        // مغلق افتراضياً: صفر صفوف، لا كل الصفوف
        $this->assertSame(0, User::count());
        Tenancy::runAsPlatform(fn () => $this->assertSame(2, User::count()));
    }

    public function test_users_of_one_company_are_invisible_to_another(): void
    {
        $this->seedReference();
        $a = $this->makeCompany('a', 'أ');
        $b = $this->makeCompany('b', 'ب');

        $this->makeUser($a);
        $this->makeUser($b);

        Tenancy::runFor($a, fn () => $this->assertSame(1, User::count()));
        Tenancy::runAsPlatform(fn () => $this->assertSame(2, User::count()));
    }
}
