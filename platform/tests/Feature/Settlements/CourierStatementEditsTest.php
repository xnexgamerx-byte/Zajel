<?php

namespace Tests\Feature\Settlements;

use App\Actions\Returns\ReceiveReturns;
use App\Actions\Settlements\BuildCourierSettlement;
use App\Actions\Settlements\ConfirmCourierSettlement;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierSettlement;
use App\Models\CourierSettlementShipment;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * كشف المندوب (docs/plan/59): للواصل وحده — والراجع يُستلم في «استلام الراجع» — وعمولته تُصحَّح
 * من سطر المسودّة قبل الاستلام. و«راسِل تاجراً» برسالة الموظّف الثابتة.
 */
class CourierStatementEditsTest extends TestCase
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
            'commission_per_delivery' => 3000, 'commission_per_return' => 0,
        ]));
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function shipment(array $path): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($path) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'الزبون', 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'cod_amount' => 25_000,
            ], $this->owner);
            foreach ($path as $status) {
                app(ChangeShipmentStatus::class)->handle($shipment->refresh(), $status, $this->owner,
                    ['courier_id' => $status === ShipmentStatus::OutForDelivery ? $this->courier->id : null]);
                if ($status === ShipmentStatus::Returning) {
                    app(ReceiveReturns::class)->handle([$shipment->id], $this->owner);
                }
            }

            return $shipment->refresh();
        });
    }

    private function delivered(): Shipment
    {
        return $this->shipment([ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered]);
    }

    private function returned(): Shipment
    {
        return $this->shipment([ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery, ShipmentStatus::FailedAttempt,
            ShipmentStatus::Returning, ShipmentStatus::Returned]);
    }

    private function draft(): CourierSettlement
    {
        return Tenancy::runFor($this->company, fn () => app(BuildCourierSettlement::class)->handle($this->courier->refresh(), $this->owner));
    }

    public function test_the_statement_holds_delivered_shipments_and_returns_only_when_they_pay(): void
    {
        $sold = $this->delivered();
        $back = $this->returned();

        $draft = $this->draft();
        $this->assertSame([$sold->id], Tenancy::runFor($this->company, fn () => $draft->lines()->pluck('shipment_id')->all()));
        $this->assertSame(22_000, (int) $draft->net_amount);

        // شركةٌ تدفع للمندوب على الراجع: أجرته تُحاسَب
        Tenancy::runFor($this->company, fn () => $back->forceFill(['courier_commission' => 1000])->save());
        $this->assertContains($back->id, Tenancy::runFor($this->company,
            fn () => app(BuildCourierSettlement::class)->eligible($this->courier)->pluck('id')->all()));
    }

    public function test_an_old_draft_drops_its_returns_without_money(): void
    {
        $sold = $this->delivered();
        $back = $this->returned();
        $draft = $this->draft();

        // مسودّةٌ بُنيت قبل التغيير وفيها راجعٌ بصفرين
        Tenancy::runFor($this->company, function () use ($draft, $back) {
            CourierSettlementShipment::create(BuildCourierSettlement::line($draft, $back));
            BuildCourierSettlement::refreshTotals($draft);
        });
        $this->assertSame(2, (int) $draft->fresh()->shipments_count);

        $this->actingAs($this->owner)->get($this->host()."/settlements/couriers/{$draft->id}")
            ->assertOk()->assertSee($sold->number)->assertDontSee($back->number)->assertSee('كشف المندوب للواصل وحده');
        $this->assertSame(1, (int) Tenancy::runFor($this->company, fn () => $draft->fresh()->shipments_count));
    }

    public function test_the_courier_fee_is_corrected_from_the_draft_line(): void
    {
        $sold = $this->delivered();
        $draft = $this->draft();

        $this->actingAs($this->owner)->get($this->host()."/settlements/couriers/{$draft->id}")
            ->assertOk()->assertSee('name="courier_commission"', false);

        $this->actingAs($this->owner)->post($this->host()."/settlements/couriers/{$draft->id}/commission", [
            'shipment_id' => $sold->id, 'courier_commission' => '٥ 000',
        ])->assertSessionHasNoErrors()->assertSessionHas('success', "أجرة المندوب على {$sold->number}: 3,000 ← 5,000.");

        Tenancy::runFor($this->company, function () use ($sold, $draft) {
            $this->assertSame(5000, (int) $sold->refresh()->courier_commission);
            $this->assertSame(5000, (int) $draft->lines()->value('commission'));
            $this->assertSame(5000, (int) $draft->refresh()->commission_total);
            $this->assertSame(20_000, (int) $draft->net_amount);
            $this->assertTrue(ShipmentEvent::where('shipment_id', $sold->id)->where('event_type', 'edited')
                ->where('note', 'like', '%كشف '.$draft->code.'%')->exists());
        });

        // من لا يعدّل الأجور لا يعدّلها من هنا
        $clerk = $this->makeUser($this->company, UserRole::CustomerService);
        $this->actingAs($clerk)->post($this->host()."/settlements/couriers/{$draft->id}/commission", [
            'shipment_id' => $sold->id, 'courier_commission' => '8000',
        ])->assertForbidden();

        // والمُقفَل لا يُمسّ
        Tenancy::runFor($this->company, fn () => app(ConfirmCourierSettlement::class)->handle($draft->refresh(), $this->owner));
        $this->actingAs($this->owner)->post($this->host()."/settlements/couriers/{$draft->id}/commission", [
            'shipment_id' => $sold->id, 'courier_commission' => '8000',
        ])->assertStatus(422);
        $this->assertSame(5000, (int) Tenancy::runFor($this->company, fn () => $sold->refresh()->courier_commission));
    }

    public function test_a_new_message_offers_the_pinned_text_with_the_merchant_to_fill(): void
    {
        Tenancy::runFor($this->company, fn () => $this->owner->forceFill([
            'merchant_message' => "السلام عليكم {التاجر}،\nمن {الموظف} — {الشركة}",
        ])->save());

        $this->actingAs($this->owner)->get($this->host().'/conversations')->assertOk()
            ->assertSee('أدرج رسالتي الثابتة')
            ->assertSee('data-fill-merchant="#merchant_id"', false)
            ->assertSee('data-store="'.$this->merchant->business_name.'"', false)
            ->assertSee('من '.$this->owner->name.' — الزاجل')
            ->assertSee('{التاجر}');
    }
}
