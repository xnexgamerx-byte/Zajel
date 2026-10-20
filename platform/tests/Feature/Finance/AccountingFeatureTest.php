<?php

namespace Tests\Feature\Finance;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\Feature;
use App\Enums\ShipmentStatus;
use App\Models\Company;
use App\Models\Courier;
use App\Models\FinancialAlert;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * المحاسبة ميزةٌ في النواة (docs/plan/68 §١٩): مفتوحةٌ لكل شركةٍ ما لم تُغلق لها. إغلاقها يُخفي
 * شاشاتها وروابطها ويوقف فحص تنبيهاتها، والوجبات والسحب والإعدادات المالية باقية، والدفتر
 * يبقى يُكتب: فتحها ثانيةً يعرض أرقاماً كاملة.
 */
class AccountingFeatureTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->owner = $this->makeUser($this->company);
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function deliver(): void
    {
        $merchant = $this->makeMerchant($this->company);

        Tenancy::runFor($this->company, function () use ($merchant) {
            $courier = Courier::firstOrCreate(['code' => 'C1'], ['name' => 'أحمد', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active']);
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $merchant->id, 'recipient_name' => 'علي', 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => 50_000,
            ], $this->owner);
            foreach ([ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered] as $status) {
                app(ChangeShipmentStatus::class)->handle($shipment->refresh(), $status, $this->owner,
                    ['courier_id' => $status === ShipmentStatus::OutForDelivery ? $courier->id : null]);
            }
        });
    }

    public function test_accounting_is_open_in_a_company_nobody_decided_for(): void
    {
        $this->assertTrue(Feature::Accounting->included());

        $this->actingAs($this->owner)->get($this->host().'/finance/trial-balance')->assertOk();
        $this->actingAs($this->owner)->get($this->host().'/profits/cash')->assertOk();
        $this->actingAs($this->owner)->get($this->host().'/profits')
            ->assertOk()->assertSee('/finance/ledger', false)->assertSee('الموقف النقدي');
    }

    public function test_closing_it_hides_its_screens_and_links_and_keeps_the_rest(): void
    {
        $this->setFeature($this->company, Feature::Accounting, false);

        foreach (['/finance/trial-balance', '/finance/ledger', '/finance/periods', '/finance/periods/review', '/finance/opening', '/profits/cash'] as $path) {
            $this->actingAs($this->owner)->get($this->host().$path)->assertForbidden();
        }
        $this->actingAs($this->owner)->post($this->host().'/operational-alerts/finance/scan')->assertForbidden();

        // ما يبقى: لوحة الأرباح بلا «تتبّع الرقم»، والوجبات والسحب، والإعدادات المالية التي تعمل بها
        $this->actingAs($this->owner)->get($this->host().'/profits')
            ->assertOk()->assertDontSee('/finance/ledger', false)->assertDontSee('/finance/trial-balance', false)->assertDontSee('/profits/cash', false);
        $this->actingAs($this->owner)->get($this->host().'/profits/batches')->assertOk();
        $this->actingAs($this->owner)->get($this->host().'/profits/withdrawals')->assertOk();
        $this->actingAs($this->owner)->get($this->host().'/finance/settings')->assertOk();

        // ولا تبويب للتنبيهات المالية
        $this->actingAs($this->owner)->get($this->host().'/operational-alerts?tab=finance')
            ->assertOk()->assertDontSee('التنبيهات المالية');
        $this->actingAs($this->owner)->get($this->host().'/')
            ->assertOk()->assertDontSee('ميزان المراجعة')->assertDontSee('إقفال الفترات');
    }

    public function test_a_closed_company_is_not_scanned_but_its_journal_keeps_being_written(): void
    {
        $this->setFeature($this->company, Feature::Accounting, false);
        $this->deliver();
        $this->travel(3)->days();

        $this->artisan('finance:alerts')->assertSuccessful();
        $this->assertSame(0, Tenancy::runFor($this->company, fn () => FinancialAlert::count()));

        // الدفتر كامل: يُفتح ثانيةً فيرى كل ما جرى وهو مغلق
        $this->assertGreaterThan(0, DB::table('journal_entries')->where('company_id', $this->company->id)->count());

        $this->setFeature($this->company, Feature::Accounting);
        $this->artisan('finance:alerts')->assertSuccessful();
        $this->assertGreaterThan(0, Tenancy::runFor($this->company, fn () => FinancialAlert::count()), 'المندوب لم يسلّم نقده منذ ثلاثة أيام.');
        $this->actingAs($this->owner)->get($this->host().'/finance/trial-balance')->assertOk()->assertSee('متوازن');
    }
}
