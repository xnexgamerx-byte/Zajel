<?php

namespace Tests\Feature\Settlements;

use App\Actions\Returns\ReceiveReturns;
use App\Actions\Settlements\BuildCourierSettlement;
use App\Actions\Settlements\BuildMerchantSettlement;
use App\Actions\Settlements\ConfirmCourierSettlement;
use App\Actions\Settlements\PayMerchantSettlement;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierSettlement;
use App\Models\Merchant;
use App\Models\MerchantSettlement;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Ledger;
use App\Support\Permissions\Ability;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تعديل الكشف المسودّة: تُحدَّد شحناتٌ فيُحاسَب عليها وحدها في كشفٍ يُقفَل — وتبقى البقية في
 * المسودّة — وتُضاف إليه شحناتٌ تنتظر التسوية خارجه. المجاميع من السطور دائماً، والمُقفَل لا يُمسّ.
 */
class EditDraftSettlementTest extends TestCase
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
        $this->merchant = $this->makeMerchant($this->company);   // توصيل ٥٠٠٠، راجع ٢٥٠٠
        $this->owner = $this->makeUser($this->company);

        $this->courier = Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => 'C1', 'name' => 'عباس', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active',
            'commission_per_delivery' => 4000, 'commission_per_return' => 1000,
        ]));
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function shipment(int $cod, array $path): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($cod, $path) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'الزبون', 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'cod_amount' => $cod,
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

    private function delivered(int $cod): Shipment
    {
        return $this->shipment($cod, [ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered]);
    }

    private function returned(): Shipment
    {
        return $this->shipment(40_000, [ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery, ShipmentStatus::FailedAttempt,
            ShipmentStatus::Returning, ShipmentStatus::Returned]);
    }

    private function courierDraft(): CourierSettlement
    {
        return Tenancy::runFor($this->company, fn () => app(BuildCourierSettlement::class)->handle($this->courier->refresh(), $this->owner));
    }

    private function fresh(CourierSettlement|MerchantSettlement $settlement): CourierSettlement|MerchantSettlement
    {
        return Tenancy::runFor($this->company, fn () => $settlement->fresh());
    }

    private function onSheet(CourierSettlement|MerchantSettlement $settlement): array
    {
        return Tenancy::runFor($this->company, fn () => $settlement->lines()->orderBy('shipment_id')->pluck('shipment_id')->all());
    }

    /** «حاسب المندوب على المحدَّد»: يُقفَل كشفٌ بالمحدَّد ويُستلم نقده، وتبقى البقية في المسودّة برمزها */
    public function test_the_courier_is_settled_on_the_selected_shipments_and_the_rest_stay_in_the_draft(): void
    {
        [$a, $b, $c] = [$this->delivered(60_000), $this->delivered(70_000), $this->delivered(50_000)];
        $draft = $this->courierDraft();
        $this->assertSame([3, 180_000, 12_000, 168_000], [(int) $draft->shipments_count, (int) $draft->cod_total,
            (int) $draft->commission_total, (int) $draft->net_amount]);

        // الصفحة: مربّعٌ لكل سطرٍ بصافيه، وما يُحدَّد يظهر أسفلها بعدده و«حاسب المندوب على المحدَّد»
        $this->actingAs($this->owner)->get($this->host().'/settlements/couriers/'.$draft->id)
            ->assertOk()
            ->assertSee('حدّد شحناتٍ لتحاسب المندوب عليها وحدها، وتبقى البقية في المسودّة.')
            ->assertSee('data-picked-bar="settle"', false)->assertSee('data-picked-total="3"', false)
            ->assertSee('value="'.$b->id.'" form="settle"', false)->assertSee('data-net="66000"', false)
            ->assertSee('حاسب المندوب على المحدَّد')
            ->assertSee('لا شحنات أخرى تنتظر التسوية.');

        $response = $this->actingAs($this->owner)->post($this->host().'/settlements/couriers/'.$draft->id.'/confirm', [
            'shipment_ids' => [$b->id], 'deductions' => 1000, 'notes' => 'دفعة أولى',
        ])->assertSessionHasNoErrors();

        $part = Tenancy::runFor($this->company, fn () => CourierSettlement::whereKeyNot($draft->id)->sole());
        $response->assertSessionHas('success', "حوسب المندوب على شحنة واحدة في كشف {$part->code}، واستُلم منه 67,000 د.ع."
            ." وبقيت شحنتان في المسودّة {$draft->code}.");

        $this->assertSame(['confirmed', [$b->id], 70_000, 4000, 1000, 67_000, 'دفعة أولى'], [$part->status, $this->onSheet($part),
            (int) $part->cod_total, (int) $part->commission_total, (int) $part->deductions, (int) $part->net_amount, $part->notes]);

        $left = $this->fresh($draft);
        $this->assertSame(['draft', [$a->id, $c->id], 110_000, 8000, 102_000], [$left->status, $this->onSheet($draft),
            (int) $left->cod_total, (int) $left->commission_total, (int) $left->net_amount]);

        Tenancy::runFor($this->company, function () use ($a, $b, $c, $part) {
            $this->assertSame([null, $part->id, null], [$a->fresh()->courier_settlement_id,
                $b->fresh()->courier_settlement_id, $c->fresh()->courier_settlement_id]);
            // بيد المندوب ما بقي في المسودّة، والدفتر مطابق
            $this->assertSame(110_000, (int) $this->courier->fresh()->cash_in_hand);
            $this->assertCount(0, app(Ledger::class)->balancesOff()['couriers']);

            $log = AuditLog::where('action', 'settlement_draft_edited')->sole();
            $this->assertSame([[$b->number], $part->code], [$log->new_values['settled'], $log->new_values['into']]);
        });

        // ما سُلِّم بعدها يُضاف إلى المسودّة، وإقفالها بلا تحديد يقبض البقية كلّها
        $late = $this->delivered(30_000);
        $this->actingAs($this->owner)->get($this->host().'/settlements/couriers/'.$draft->id)
            ->assertOk()->assertSee('تنتظر التسوية خارج الكشف — شحنة واحدة')
            ->assertSee('value="'.$late->id.'" form="add-lines"', false);
        $this->actingAs($this->owner)->post($this->host().'/settlements/couriers/'.$draft->id.'/add', ['shipment_ids' => [$late->id]])
            ->assertSessionHas('success', "أُضيفت إلى كشف {$draft->code}: شحنة واحدة.");

        $this->actingAs($this->owner)->post($this->host().'/settlements/couriers/'.$draft->id.'/confirm')
            ->assertSessionHas('success', "أُقفِل كشف {$draft->code} واستُلم النقد.");
        $this->assertSame(['confirmed', [$a->id, $c->id, $late->id]], [$this->fresh($draft)->status, $this->onSheet($draft)]);

        Tenancy::runFor($this->company, function () {
            $this->assertSame(0, (int) $this->courier->fresh()->cash_in_hand);
            $this->assertCount(0, app(Ledger::class)->balancesOff()['couriers']);
        });
    }

    /** ما سُلِّم بعد فتح الكشف يُضاف إليه، والإقفال يقبض ما في السطور وحده */
    public function test_a_shipment_delivered_after_the_draft_is_added_and_the_rest_waits(): void
    {
        $first = $this->delivered(60_000);
        $draft = $this->courierDraft();
        $late = $this->delivered(30_000);
        $left = $this->delivered(20_000);

        Tenancy::runFor($this->company, fn () => app(\App\Actions\Settlements\EditDraftSettlement::class)
            ->add($draft, [$late->id], $this->owner));
        $this->assertSame([$first->id, $late->id], $this->onSheet($draft));

        Tenancy::runFor($this->company, fn () => app(ConfirmCourierSettlement::class)->handle($this->fresh($draft), $this->owner));

        Tenancy::runFor($this->company, function () use ($first, $late, $left, $draft) {
            $this->assertSame([$draft->id, $draft->id, null], [$first->fresh()->courier_settlement_id,
                $late->fresh()->courier_settlement_id, $left->fresh()->courier_settlement_id]);
            // بيد المندوب ما لم يدخل الكشف وحده، والدفتر مطابق
            $this->assertSame(20_000, (int) $this->courier->fresh()->cash_in_hand);
            $off = app(Ledger::class)->balancesOff();
            $this->assertCount(0, $off['couriers']);
        });
    }

    /** تحديد الكل كالإقفال بلا تحديد: الكشف نفسه لا كشفٌ جديد — والمُقفَل لا يُمسّ */
    public function test_selecting_every_shipment_settles_the_draft_itself_and_a_confirmed_one_is_not_touched(): void
    {
        [$a, $b] = [$this->delivered(60_000), $this->delivered(70_000)];
        $draft = $this->courierDraft();

        // ما ليس من سطوره لا يُحاسَب عليه
        $this->actingAs($this->owner)->post($this->host().'/settlements/couriers/'.$draft->id.'/confirm', ['shipment_ids' => [999_999]])
            ->assertSessionHasErrors(['shipment_ids' => 'اختر شحنةً من سطور الكشف.']);
        $this->assertSame('draft', $this->fresh($draft)->status);

        $this->actingAs($this->owner)->post($this->host().'/settlements/couriers/'.$draft->id.'/confirm', ['shipment_ids' => [$a->id, $b->id]])
            ->assertSessionHas('success', "أُقفِل كشف {$draft->code} واستُلم النقد.");
        $this->assertSame(['confirmed', 1], [$this->fresh($draft)->status,
            Tenancy::runFor($this->company, fn () => CourierSettlement::count())]);

        $this->actingAs($this->owner)->get($this->host().'/settlements/couriers/'.$draft->id)
            ->assertOk()->assertDontSee('حاسب المندوب على المحدَّد')->assertDontSee('data-picked-bar', false)
            ->assertDontSee('تنتظر التسوية خارج الكشف');
        $this->actingAs($this->owner)->post($this->host().'/settlements/couriers/'.$draft->id.'/confirm', ['shipment_ids' => [$a->id]])
            ->assertSessionHasErrors(['settlement' => "كشف {$draft->code} مُقفَل فلا يُعدَّل — تصحيحه حركةٌ في الدفتر."]);
        $this->assertSame(1, Tenancy::runFor($this->company, fn () => CourierSettlement::count()));
    }

    public function test_only_who_may_settle_edits(): void
    {
        [$a] = [$this->delivered(60_000), $this->delivered(70_000)];
        $draft = $this->courierDraft();

        $viewer = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'محاسب', 'phone' => '07701112233', 'password' => 'password',
            'role' => UserRole::Operations, 'permissions' => [Ability::MONEY_VIEW], 'is_active' => true,
        ]));

        $this->actingAs($viewer)->get($this->host().'/settlements/couriers/'.$draft->id)
            ->assertOk()->assertDontSee('حاسب المندوب على المحدَّد')->assertDontSee('form="settle"', false);
        $this->actingAs($viewer)->post($this->host().'/settlements/couriers/'.$draft->id.'/confirm', ['shipment_ids' => [$a->id]])
            ->assertForbidden();
        $this->assertSame('draft', $this->fresh($draft)->status);
    }

    /** كشف التاجر: يُقفَل بالمحدَّد كشفٌ يُدفع من صفحته، وتبقى البقية في المسودّة بمجاميعها */
    public function test_a_merchant_is_settled_on_the_selected_shipments_and_the_rest_stay_in_the_draft(): void
    {
        $sold = $this->delivered(60_000);   // له ٥٥٬٠٠٠
        $back = $this->returned();           // عليه ٢٬٥٠٠
        $other = $this->delivered(30_000);  // له ٢٥٬٠٠٠
        $this->settleCourierCash($this->company);

        $draft = Tenancy::runFor($this->company, fn () => app(BuildMerchantSettlement::class)->handle($this->merchant->refresh(), $this->owner));
        $this->assertSame([3, 1, 90_000, 10_000, 2500, 77_500], [(int) $draft->shipments_count, (int) $draft->returned_count,
            (int) $draft->cod_total, (int) $draft->delivery_fees_total, (int) $draft->return_fees_total, (int) $draft->net_amount]);

        $this->actingAs($this->owner)->get($this->host().'/settlements/merchants/'.$draft->id)
            ->assertOk()->assertSee('حاسب التاجر على المحدَّد')->assertSee('data-net="-2500"', false);

        $response = $this->actingAs($this->owner)->post($this->host().'/settlements/merchants/'.$draft->id.'/confirm', [
            'shipment_ids' => [$sold->id], 'notes' => 'دفعة',
        ]);
        $part = Tenancy::runFor($this->company, fn () => MerchantSettlement::whereKeyNot($draft->id)->sole());
        $response->assertRedirect($this->host().'/settlements/merchants/'.$part->id)
            ->assertSessionHas('success', "أُقفِل كشف {$part->code} بالمحدَّد: شحنة واحدة. سجّل الدفع بعد تحويل المبلغ."
                ." وبقيت شحنتان في المسودّة {$draft->code}.");

        $this->assertSame(['confirmed', [$sold->id], 55_000, 'دفعة'], [$part->status, $this->onSheet($part), (int) $part->net_amount, $part->notes]);
        $left = $this->fresh($draft);
        $this->assertSame(['draft', 2, 1, 30_000, 5000, 2500, 22_500], [$left->status, (int) $left->shipments_count, (int) $left->returned_count,
            (int) $left->cod_total, (int) $left->delivery_fees_total, (int) $left->return_fees_total, (int) $left->net_amount]);

        // يُدفع الجديد وحده، ويبقى للتاجر صافي البقية: ٢٥٬٠٠٠ − ٢٬٥٠٠
        Tenancy::runFor($this->company, function () use ($part, $sold, $back) {
            app(PayMerchantSettlement::class)->pay($part->fresh(), $this->owner, 'cash');

            $this->assertSame(22_500, (int) $this->merchant->fresh()->balance);
            $this->assertSame([$part->id, null], [$sold->fresh()->merchant_settlement_id, $back->fresh()->merchant_settlement_id]);
            $this->assertCount(0, app(Ledger::class)->balancesOff()['merchants']);
        });

        // وتحديد كل ما بقي يُقفل المسودّة نفسها
        $this->actingAs($this->owner)->post($this->host().'/settlements/merchants/'.$draft->id.'/confirm', ['shipment_ids' => [$back->id, $other->id]])
            ->assertSessionHas('success', "أُقفِل كشف {$draft->code}. سجّل الدفع بعد تحويل المبلغ.");
        $this->assertSame(['confirmed', 2], [$this->fresh($draft)->status,
            Tenancy::runFor($this->company, fn () => MerchantSettlement::count())]);
    }
}
