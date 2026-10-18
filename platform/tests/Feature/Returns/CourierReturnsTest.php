<?php

namespace Tests\Feature\Returns;

use App\Actions\Returns\ReceiveReturns;
use App\Actions\Settlements\BuildCourierSettlement;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «كشوفات رواجع المندوبين»: كلّ راجعٍ رجع على يد مندوب — بيده، أو بالمخزن، أو عند تاجره —
 * وهل حوسب المندوب عليه.
 */
class CourierReturnsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $staff;

    private Courier $courier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->staff = $this->makeUser($this->company);

        $this->courier = Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => 'C1', 'name' => 'أحمد الساعدي', 'phone' => '07720000001',
            'type' => 'delivery', 'status' => 'active',
            'commission_per_delivery' => 1500, 'commission_per_return' => 750,
        ]));
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function walk(array $path): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($path) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي حسين', 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => 50_000,
            ], $this->staff);

            foreach ($path as $step) {
                $step === 'receive'
                    ? app(ReceiveReturns::class)->handle([$shipment->id], $this->staff)
                    : app(ChangeShipmentStatus::class)->handle($shipment->refresh(), $step, $this->staff, [
                        'courier_id' => $step === ShipmentStatus::OutForDelivery ? $this->courier->id : null,
                    ]);
            }

            return $shipment->refresh();
        });
    }

    private function returning(): array
    {
        return [ShipmentStatus::PickedUp, ShipmentStatus::AtHub, ShipmentStatus::OutForDelivery,
            ShipmentStatus::FailedAttempt, ShipmentStatus::Returning];
    }

    public function test_every_return_shows_where_it_is_and_whether_the_courier_was_settled(): void
    {
        $withCourier = $this->walk($this->returning());
        $atHub = $this->walk([...$this->returning(), 'receive']);
        $toMerchant = $this->walk([...$this->returning(), 'receive', ShipmentStatus::Returned]);
        $delivered = $this->walk([ShipmentStatus::PickedUp, ShipmentStatus::AtHub, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered]);

        $this->actingAs($this->staff)->get($this->host().'/returns/couriers')
            ->assertOk()
            ->assertSee('كشوفات رواجع المندوبين')
            ->assertSee('أحمد الساعدي')
            ->assertSee($withCourier->number)->assertSee($atHub->number)->assertSee($toMerchant->number)
            // المسلَّمة ليست راجعاً
            ->assertDontSee($delivered->number)
            ->assertSee('لم يُستلم منه بعد')
            ->assertSee('سُلّم للتاجر')
            // عمولة الراجع قُيِّدت حين سُلّم لتاجره، ولم تدخل كشفاً بعد
            ->assertSee('لم يُحاسَب بعد')
            ->assertSee('عمولته عند تسليمه للتاجر');

        // كلّ مرحلةٍ وحدها
        $this->actingAs($this->staff)->get($this->host().'/returns/couriers?stage=with_courier')
            ->assertSee($withCourier->number)->assertDontSee($atHub->number)->assertDontSee($toMerchant->number);
        $this->actingAs($this->staff)->get($this->host().'/returns/couriers?stage=at_hub')
            ->assertSee($atHub->number)->assertDontSee($withCourier->number)->assertDontSee($toMerchant->number);
        $this->actingAs($this->staff)->get($this->host().'/returns/couriers?settled=no')
            ->assertSee($toMerchant->number)->assertDontSee($withCourier->number);

        // حوسب في كشفه: يظهر برمز الكشف
        $settlement = Tenancy::runFor($this->company, function () use ($toMerchant) {
            $settlement = app(BuildCourierSettlement::class)->handle($this->courier, $this->staff);
            $toMerchant->forceFill(['courier_settlement_id' => $settlement->id])->save();

            return $settlement;
        });

        $this->actingAs($this->staff)->get($this->host().'/returns/couriers?settled=yes')
            ->assertSee($toMerchant->number)->assertSee('حوسب · '.$settlement->code)->assertDontSee($atHub->number);
    }

    public function test_the_menu_lists_the_screen_under_returns(): void
    {
        $this->actingAs($this->staff)->get($this->host().'/returns/couriers')->assertOk();
        $this->actingAs($this->staff)->get($this->host().'/')
            ->assertSee(route('returns.couriers', [], false), false);
    }
}
