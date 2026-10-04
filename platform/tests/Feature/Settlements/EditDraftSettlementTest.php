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
 * تعديل الكشف المسودّة: تُحدَّد شحناتٌ فتُخرَج منه — تبقى بلا تسوية وتدخل الكشف التالي —
 * وتُضاف إليه شحناتٌ تنتظر التسوية خارجه. المجاميع من السطور دائماً، والمُقفَل لا يُمسّ.
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

    public function test_selected_shipments_leave_the_draft_and_come_back(): void
    {
        [$a, $b, $c] = [$this->delivered(60_000), $this->delivered(70_000), $this->delivered(50_000)];
        $draft = $this->courierDraft();
        $this->assertSame([3, 180_000, 12_000, 168_000], [(int) $draft->shipments_count, (int) $draft->cod_total,
            (int) $draft->commission_total, (int) $draft->net_amount]);

        // الصفحة: مربّعٌ لكل سطر، وزرّ الإخراج — ولا شيء تحت «تنتظر التسوية» بعد
        $this->actingAs($this->owner)->get($this->host().'/settlements/couriers/'.$draft->id)
            ->assertOk()
            ->assertSee('إخراج المحدَّد من الكشف')
            ->assertSee('value="'.$b->id.'" form="remove-lines"', false)
            ->assertSee('لا شحنات أخرى تنتظر التسوية.');

        // تُحدَّد شحنةٌ واحدة فتُخرَج وحدها
        $this->actingAs($this->owner)->post($this->host().'/settlements/couriers/'.$draft->id.'/remove', ['shipment_ids' => [$b->id]])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', "أُخرجت من كشف {$draft->code}: شحنة واحدة. تبقى بلا تسوية وتدخل الكشف التالي.");

        $edited = $this->fresh($draft);
        $this->assertSame([$a->id, $c->id], $this->onSheet($draft));
        $this->assertSame([2, 110_000, 8000, 102_000], [(int) $edited->shipments_count, (int) $edited->cod_total,
            (int) $edited->commission_total, (int) $edited->net_amount]);

        // وتظهر تحت «تنتظر التسوية» فتعود بتحديدها
        $this->actingAs($this->owner)->get($this->host().'/settlements/couriers/'.$draft->id)
            ->assertOk()->assertSee('تنتظر التسوية خارج الكشف — شحنة واحدة')
            ->assertSee('value="'.$b->id.'" form="add-lines"', false);

        $this->actingAs($this->owner)->post($this->host().'/settlements/couriers/'.$draft->id.'/add', ['shipment_ids' => [$b->id]])
            ->assertSessionHas('success', "أُضيفت إلى كشف {$draft->code}: شحنة واحدة.");
        $this->assertSame(180_000, (int) $this->fresh($draft)->cod_total);

        $this->assertSame(['removed', 'added'], Tenancy::runFor($this->company, fn () => AuditLog::where('action', 'settlement_draft_edited')
            ->orderBy('id')->get()->map(fn ($log) => array_key_first(array_diff_key($log->new_values, ['code' => 1])))->all()));
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

    public function test_a_draft_is_not_emptied_and_a_confirmed_one_is_not_touched(): void
    {
        [$a, $b] = [$this->delivered(60_000), $this->delivered(70_000)];
        $draft = $this->courierDraft();

        $this->actingAs($this->owner)->post($this->host().'/settlements/couriers/'.$draft->id.'/remove', ['shipment_ids' => [$a->id, $b->id]])
            ->assertSessionHasErrors(['shipment_ids' => 'لا يبقى الكشف بلا شحنات — احذف الكشف كلّه بدل إخراج شحناته كلّها.']);
        $this->assertSame(2, (int) $this->fresh($draft)->shipments_count);

        Tenancy::runFor($this->company, fn () => app(ConfirmCourierSettlement::class)->handle($this->fresh($draft), $this->owner));

        $this->actingAs($this->owner)->get($this->host().'/settlements/couriers/'.$draft->id)
            ->assertOk()->assertDontSee('إخراج المحدَّد من الكشف')->assertDontSee('تنتظر التسوية خارج الكشف');
        $this->actingAs($this->owner)->post($this->host().'/settlements/couriers/'.$draft->id.'/remove', ['shipment_ids' => [$a->id]])
            ->assertSessionHasErrors(['settlement' => "كشف {$draft->code} مُقفَل فلا يُعدَّل — تصحيحه حركةٌ في الدفتر."]);
        $this->assertSame(2, (int) $this->fresh($draft)->shipments_count);
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
            ->assertOk()->assertDontSee('إخراج المحدَّد من الكشف')->assertDontSee('form="remove-lines"', false);
        $this->actingAs($viewer)->post($this->host().'/settlements/couriers/'.$draft->id.'/remove', ['shipment_ids' => [$a->id]])
            ->assertForbidden();
    }

    /** كشف التاجر: الراجع سطرُه بأجرة رجوعه، والمجاميع تُعاد من السطور بعد الإخراج */
    public function test_a_merchant_draft_is_edited_and_its_totals_follow(): void
    {
        $sold = $this->delivered(60_000);   // له ٥٥٬٠٠٠
        $back = $this->returned();           // عليه ٢٬٥٠٠
        $other = $this->delivered(30_000);  // له ٢٥٬٠٠٠

        $draft = Tenancy::runFor($this->company, fn () => app(BuildMerchantSettlement::class)->handle($this->merchant->refresh(), $this->owner));
        $this->assertSame([3, 1, 90_000, 10_000, 2500, 77_500], [(int) $draft->shipments_count, (int) $draft->returned_count,
            (int) $draft->cod_total, (int) $draft->delivery_fees_total, (int) $draft->return_fees_total, (int) $draft->net_amount]);

        $this->actingAs($this->owner)->get($this->host().'/settlements/merchants/'.$draft->id)
            ->assertOk()->assertSee('إخراج المحدَّد من الكشف');

        $this->actingAs($this->owner)->post($this->host().'/settlements/merchants/'.$draft->id.'/remove', ['shipment_ids' => [$back->id, $other->id]])
            ->assertSessionHas('success', "أُخرجت من كشف {$draft->code}: شحنتان. تبقى بلا تسوية وتدخل الكشف التالي.");

        $edited = $this->fresh($draft);
        $this->assertSame([1, 0, 60_000, 5000, 0, 55_000], [(int) $edited->shipments_count, (int) $edited->returned_count,
            (int) $edited->cod_total, (int) $edited->delivery_fees_total, (int) $edited->return_fees_total, (int) $edited->net_amount]);

        // يُقفَل ويُدفع بما فيه وحده، ويبقى للتاجر صافي ما أُخرج: ٢٥٬٠٠٠ − ٢٬٥٠٠
        Tenancy::runFor($this->company, function () use ($edited, $sold, $back) {
            $pay = app(PayMerchantSettlement::class);
            $pay->confirm($edited, $this->owner);
            $pay->pay($edited->fresh(), $this->owner, 'cash');

            $this->assertSame(22_500, (int) $this->merchant->fresh()->balance);
            $this->assertSame([$edited->id, null], [$sold->fresh()->merchant_settlement_id, $back->fresh()->merchant_settlement_id]);
            $this->assertCount(0, app(Ledger::class)->balancesOff()['merchants']);
        });
    }
}
