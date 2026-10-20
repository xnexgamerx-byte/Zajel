<?php

namespace Tests\Feature\Finance;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Models\Company;
use App\Models\Courier;
use App\Models\FinancialAlert;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * أدوات المرحلة السابعة (docs/plan/68): القبول يكتب تقريره على الخادم ولا يترك أثراً في البيانات
 * (التنبيهات تُفتح في معاملةٍ تُلغى)، ويقيس الشاشات بطلبٍ داخليّ؛ وبيانات القياس لا تُملأ في الإنتاج.
 */
class AcceptanceCommandTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $merchant = $this->makeMerchant($this->company);
        $owner = $this->makeUser($this->company);

        Tenancy::runFor($this->company, function () use ($merchant, $owner) {
            $courier = Courier::create(['code' => 'C1', 'name' => 'أحمد', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active']);
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $merchant->id, 'recipient_name' => 'علي', 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => 50_000,
            ], $owner);
            foreach ([ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered] as $status) {
                app(ChangeShipmentStatus::class)->handle($shipment->refresh(), $status, $owner,
                    ['courier_id' => $status === ShipmentStatus::OutForDelivery ? $courier->id : null]);
            }
        });
    }

    protected function tearDown(): void
    {
        File::delete(File::glob(storage_path('app/acceptance/zajel-*.md')));
        parent::tearDown();
    }

    public function test_acceptance_reports_checks_invariants_and_pages_without_leaving_alerts(): void
    {
        $this->travel(3)->days();

        $this->artisan('finance:acceptance', ['--company' => 'zajel'])
            ->expectsOutputToContain('ميزان المراجعة = صفر')
            ->expectsOutputToContain('لوحة الأرباح')
            ->assertSuccessful();

        $reports = File::glob(storage_path('app/acceptance/zajel-*.md'));
        $this->assertCount(1, $reports);
        $report = File::get($reports[0]);
        $this->assertStringContainsString('## زمن الشاشات المالية', $report);
        $this->assertStringContainsString('تأخّر مندوبٍ عن تسليم النقد', $report, 'المندوب لم يسلّم نقده منذ ثلاثة أيام.');

        // الفحص جرى في معاملةٍ أُلغيت: لا تنبيه بقي
        $this->assertSame(0, Tenancy::runFor($this->company, fn () => FinancialAlert::count()));
    }

    public function test_load_test_data_is_never_written_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('finance:load-test', ['--slug' => 'perf', '--days' => 1, '--per-day' => 1])->assertFailed();
        $this->assertFalse(Tenancy::runAsPlatform(fn () => Company::where('slug', 'perf')->exists()));
    }
}
