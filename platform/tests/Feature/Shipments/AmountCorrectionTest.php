<?php

namespace Tests\Feature\Shipments;

use App\Actions\Settlements\BuildCourierSettlement;
use App\Actions\Settlements\BuildMerchantSettlement;
use App\Actions\Settlements\ConfirmCourierSettlement;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierSettlement;
use App\Models\Merchant;
use App\Models\MerchantSettlement;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * مبلغٌ كُتب خطأً — 25 بدل 25,000 — يُصحَّح من «تعديل بصلاحية خاصّة» فيتحدّث في كل مكان
 * (docs/plan/61): الوصل، وكشف المندوب، وكشف التاجر، والحساب — ويصل التاجرَ إشعارٌ يُطفأ من الإعدادات.
 */
class AmountCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private Courier $courier;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);
        $this->courier = Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => 'C1', 'name' => 'عباس', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active',
            'commission_per_delivery' => 3000,
        ]));
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    /** @param  list<ShipmentStatus>  $path */
    private function shipment(array $path, int $amount = 25): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($path, $amount) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'نورهان', 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'cod_amount' => $amount,
            ], $this->owner);
            foreach ($path as $status) {
                app(ChangeShipmentStatus::class)->handle($shipment->refresh(), $status, $this->owner,
                    ['courier_id' => $status === ShipmentStatus::OutForDelivery ? $this->courier->id : null]);
            }

            return $shipment->refresh();
        });
    }

    private function delivered(): Shipment
    {
        return $this->shipment([ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered]);
    }

    private function correct(Shipment $shipment, int $amount): TestResponse
    {
        return $this->actingAs($this->owner)->put($this->host().'/shipments/'.$shipment->id.'/override', [
            'recipient_name' => $shipment->recipient_name, 'recipient_phone' => $shipment->recipient_phone,
            'cod_amount' => $amount, 'reason' => 'التاجر كتب 25 بدل 25 ألف',
        ]);
    }

    private function balance(): int
    {
        return (int) Tenancy::runFor($this->company, fn () => Merchant::find($this->merchant->id)->balance);
    }

    public function test_a_delivered_amount_is_corrected_on_the_waybill_the_courier_statement_and_the_merchant_account(): void
    {
        $shipment = $this->delivered();
        $draft = Tenancy::runFor($this->company, fn () => app(BuildCourierSettlement::class)->handle($this->courier->refresh(), $this->owner));
        $balance = $this->balance();
        $due = (int) $shipment->merchant_due;

        $this->correct($shipment, 25_000)->assertSessionHasNoErrors()->assertRedirect();

        $fixed = Tenancy::runFor($this->company, fn () => $shipment->fresh());
        $this->assertSame(25_000, (int) $fixed->cod_amount);
        $this->assertSame(25_000, (int) $fixed->collected_amount);
        $this->assertSame($due + 24_975, (int) $fixed->merchant_due);
        $this->assertSame($balance + 24_975, $this->balance());

        // كشف المندوب (مسودّة) بالمبلغ الجديد
        $statement = Tenancy::runFor($this->company, fn () => CourierSettlement::find($draft->id));
        $this->assertSame(25_000, (int) $statement->cod_total);
        $this->actingAs($this->owner)->get($this->host().'/settlements/couriers/'.$draft->id)->assertOk()->assertSee('25,000');

        // والوصل وقائمة الشحنات
        $this->actingAs($this->owner)->get($this->host().'/shipments/'.$shipment->id)->assertOk()->assertSee('25,000');

        // والتاجر يصله إشعار
        $notice = Tenancy::runFor($this->company, fn () => Announcement::where('merchant_id', $this->merchant->id)->first());
        $this->assertNotNull($notice);
        $this->assertStringContainsString($shipment->number, $notice->title);
        $this->assertStringContainsString('25,000', $notice->body);
    }

    public function test_a_merchant_statement_draft_follows_the_corrected_amount(): void
    {
        $shipment = $this->delivered();
        Tenancy::runFor($this->company, fn () => app(ConfirmCourierSettlement::class)->handle(
            app(BuildCourierSettlement::class)->handle($this->courier->refresh(), $this->owner), $this->owner,
        ));
        $draft = Tenancy::runFor($this->company, fn () => app(BuildMerchantSettlement::class)->handle($this->merchant->refresh(), $this->owner));
        $before = (int) $draft->net_amount;

        $this->correct($shipment, 25_000)->assertSessionHasNoErrors();

        $this->assertSame($before + 24_975, (int) Tenancy::runFor($this->company, fn () => MerchantSettlement::find($draft->id)->net_amount));
    }

    public function test_the_notice_reaches_only_that_merchant_and_can_be_switched_off(): void
    {
        $shipment = $this->shipment([ShipmentStatus::PickedUp]);
        $this->correct($shipment, 25_000)->assertSessionHasNoErrors();
        $this->assertSame(25_000, (int) Tenancy::runFor($this->company, fn () => $shipment->fresh()->cod_amount));

        $other = $this->makeMerchant($this->company, 'M0002');
        [$mine, $theirs] = Tenancy::runFor($this->company, fn () => [
            User::create(['name' => 'ت1', 'phone' => '07790000001', 'password' => 'password', 'role' => UserRole::Merchant,
                'merchant_id' => $this->merchant->id, 'is_active' => true]),
            User::create(['name' => 'ت2', 'phone' => '07790000002', 'password' => 'password', 'role' => UserRole::Merchant,
                'merchant_id' => $other->id, 'is_active' => true]),
        ]);
        $this->assertSame(1, Tenancy::runFor($this->company, fn () => Announcement::for($mine)->count()));
        $this->assertSame(0, Tenancy::runFor($this->company, fn () => Announcement::for($theirs)->count()));
        // ولا تملأ قائمة الإعلانات عند الموظّف
        $this->actingAs($this->owner)->get($this->host().'/announcements')->assertOk()->assertDontSee('تغيّر مبلغ الوصل');

        // من «بيانات الشركة»: يُطفأ
        $this->actingAs($this->owner)->put($this->host().'/settings/company', [
            'primary_color' => '#FE0A0E', 'notify_amount_change' => '0',
        ])->assertSessionHasNoErrors();
        $next = $this->shipment([ShipmentStatus::PickedUp]);
        $this->correct($next, 40_000)->assertSessionHasNoErrors();
        $this->assertSame(1, Tenancy::runFor($this->company, fn () => Announcement::where('merchant_id', $this->merchant->id)->count()));
    }
}
