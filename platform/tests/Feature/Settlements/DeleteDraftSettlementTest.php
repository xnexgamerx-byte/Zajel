<?php

namespace Tests\Feature\Settlements;

use App\Actions\Merchants\SubmitMerchantRequest;
use App\Actions\Settlements\BuildCourierSettlement;
use App\Actions\Settlements\BuildMerchantSettlement;
use App\Actions\Settlements\ConfirmCourierSettlement;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierSettlement;
use App\Models\CourierSettlementShipment;
use App\Models\Merchant;
use App\Models\MerchantRequest;
use App\Models\MerchantSettlement;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Permissions\Ability;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * حذف الكشف المسودّة: المسودّة لقطةٌ بلا أثر — لا تُوسَم شحناتها ولا يُقيَّد بها شيء —
 * فتُحذف وتدخل شحناتها الكشف التالي كما هي. والمُقفَل لا يُحذف.
 */
class DeleteDraftSettlementTest extends TestCase
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
            'commission_per_delivery' => 4000,
        ]));
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function delivered(int $cod): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($cod) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'الزبون', 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'cod_amount' => $cod,
            ], $this->owner);

            $change = app(ChangeShipmentStatus::class);
            foreach ([ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered] as $status) {
                $change->handle($shipment->refresh(), $status, $this->owner,
                    ['courier_id' => $status === ShipmentStatus::OutForDelivery ? $this->courier->id : null]);
            }

            return $shipment->refresh();
        });
    }

    private function courierDraft(): CourierSettlement
    {
        return Tenancy::runFor($this->company, fn () => app(BuildCourierSettlement::class)->handle($this->courier, $this->owner));
    }

    public function test_a_courier_draft_is_deleted_and_its_shipments_go_to_the_next_statement(): void
    {
        $shipments = [$this->delivered(60_000), $this->delivered(70_000)];
        $draft = $this->courierDraft();

        // الكشف المفتوح يمنع غيره — ورسالته تدلّ على الحذف
        $this->actingAs($this->owner)->post($this->host().'/settlements/couriers', ['courier_id' => $this->courier->id])
            ->assertSessionHasErrors(['courier_id' => "للمندوب عباس كشفٌ مسودّة ({$draft->code}). أقفِله أو احذفه من صفحته قبل بناء كشف جديد."]);

        $this->actingAs($this->owner)->get($this->host().'/settlements/couriers/'.$draft->id)
            ->assertOk()->assertSee('حذف الكشف');
        // وفي سطره من القائمة
        $this->actingAs($this->owner)->get($this->host().'/settlements/couriers')
            ->assertOk()->assertSee('data-confirm="يُحذف كشف '.$draft->code.'؟', false);

        $this->actingAs($this->owner)->delete($this->host().'/settlements/couriers/'.$draft->id)
            ->assertRedirect($this->host().'/settlements/couriers')
            ->assertSessionHas('success', "حُذف كشف {$draft->code}. شحناته تدخل الكشف التالي كما هي.");

        Tenancy::runFor($this->company, function () use ($draft, $shipments) {
            $this->assertNull(CourierSettlement::find($draft->id));
            $this->assertSame(0, CourierSettlementShipment::where('courier_settlement_id', $draft->id)->count());

            // لا أثر في الحساب: نقد المندوب كما كان، وشحناته بلا كشف
            $this->assertSame(130_000, (int) $this->courier->fresh()->cash_in_hand);
            foreach ($shipments as $shipment) {
                $this->assertNull($shipment->fresh()->courier_settlement_id);
            }

            $log = AuditLog::where('action', 'settlement_draft_deleted')->sole();
            $this->assertSame([$draft->code, 'عباس', 2], [$log->old_values['code'], $log->old_values['party'], $log->old_values['shipments_count']]);
            $this->assertSame('حُذف كشفٌ مسودّة', $log->actionLabel());
        });

        // الكشف التالي يأخذ الشحنتين نفسيهما
        $next = $this->courierDraft();
        $this->assertSame([2, 130_000], [(int) $next->shipments_count, (int) $next->cod_total]);
    }

    public function test_a_confirmed_statement_is_not_deleted(): void
    {
        $this->delivered(60_000);
        $draft = $this->courierDraft();
        Tenancy::runFor($this->company, fn () => app(ConfirmCourierSettlement::class)->handle($draft, $this->owner));

        $this->actingAs($this->owner)->get($this->host().'/settlements/couriers/'.$draft->id)
            ->assertOk()->assertDontSee('حذف الكشف');

        $this->actingAs($this->owner)->delete($this->host().'/settlements/couriers/'.$draft->id)
            ->assertSessionHasErrors(['settlement' => "كشف {$draft->code} مُقفَل فلا يُحذف — تصحيحه حركةٌ في الدفتر."]);

        $this->assertNotNull(Tenancy::runFor($this->company, fn () => CourierSettlement::find($draft->id)));
    }

    public function test_only_who_may_settle_deletes(): void
    {
        $this->delivered(60_000);
        $draft = $this->courierDraft();

        $viewer = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'محاسب', 'phone' => '07701112233', 'password' => 'password',
            'role' => UserRole::Operations, 'permissions' => [Ability::MONEY_VIEW], 'is_active' => true,
        ]));

        $this->actingAs($viewer)->get($this->host().'/settlements/couriers/'.$draft->id)
            ->assertOk()->assertDontSee('حذف الكشف');
        $this->actingAs($viewer)->delete($this->host().'/settlements/couriers/'.$draft->id)->assertForbidden();

        $this->assertNotNull(Tenancy::runFor($this->company, fn () => CourierSettlement::find($draft->id)));
    }

    /** «حاسبوني» الذي أُجيب بالمسودّة يعود ينتظر الكشف التالي */
    public function test_deleting_a_merchant_draft_reopens_the_payment_request_it_answered(): void
    {
        $this->delivered(60_000);

        [$request, $draft] = Tenancy::runFor($this->company, function () {
            $request = app(SubmitMerchantRequest::class)->handle($this->merchant->refresh(), 'payment', []);

            return [$request, app(BuildMerchantSettlement::class)->handle($this->merchant->refresh(), $this->owner)];
        });
        $this->assertSame(['handled', $draft->id], Tenancy::runFor($this->company,
            fn () => [$request->fresh()->status, $request->fresh()->merchant_settlement_id]));

        $this->actingAs($this->owner)->get($this->host().'/settlements/merchants/'.$draft->id)
            ->assertOk()->assertSee('حذف الكشف');

        $this->actingAs($this->owner)->delete($this->host().'/settlements/merchants/'.$draft->id)
            ->assertRedirect($this->host().'/settlements/merchants');

        Tenancy::runFor($this->company, function () use ($request, $draft) {
            $this->assertNull(MerchantSettlement::find($draft->id));
            $reopened = $request->fresh();
            $this->assertSame(['open', null, null], [$reopened->status, $reopened->merchant_settlement_id, $reopened->handled_at]);
            $this->assertSame(1, MerchantRequest::open()->count());
        });

        // الكشف التالي يُجيبه من جديد
        $next = Tenancy::runFor($this->company, fn () => app(BuildMerchantSettlement::class)->handle($this->merchant->refresh(), $this->owner));
        $this->assertSame(['handled', $next->id], Tenancy::runFor($this->company,
            fn () => [$request->fresh()->status, $request->fresh()->merchant_settlement_id]));
    }
}
