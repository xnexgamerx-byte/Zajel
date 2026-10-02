<?php

namespace Tests\Feature\Shipments;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Transaction;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Services\Shipments\ShipmentStages;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «انتظار موافقة التسليم» (docs/plan/22 §٢): ما سلّمه المندوب جزئياً أو بمبلغٍ غير
 * المطلوب ينتظر من يعتمد مبلغه — دفعةً من القائمة أو من صفحته — ثم يُقفَل.
 */
class DeliveryApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $owner;

    private Courier $courier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);
        $this->courier = Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => 'C1', 'name' => 'أحمد', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active',
            'commission_per_delivery' => 1000,
        ]));
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    /** شحنةٌ يسلّمها المندوب بالحال والمبلغ المعطيين */
    private function delivered(ShipmentStatus $as = ShipmentStatus::Delivered, ?int $collected = null): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($as, $collected) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي', 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => 50_000,
            ], $this->owner);

            foreach ([ShipmentStatus::PickedUp, ShipmentStatus::AtHub, ShipmentStatus::OutForDelivery] as $status) {
                app(ChangeShipmentStatus::class)->handle($shipment->refresh(), $status, $this->owner,
                    ['courier_id' => $status === ShipmentStatus::OutForDelivery ? $this->courier->id : null]);
            }

            app(ChangeShipmentStatus::class)->handle($shipment->refresh(), $as, $this->owner,
                $collected === null ? [] : ['collected_amount' => $collected]);

            return $shipment->refresh();
        });
    }

    private function waiting(): array
    {
        return Tenancy::runFor($this->company, fn () => ShipmentStages::awaitingApproval(Shipment::query())
            ->orderBy('id')->pluck('number')->all());
    }

    public function test_the_stage_holds_deliveries_that_differ_from_what_was_ordered(): void
    {
        $changed = $this->delivered(collected: 45_000);
        $partial = $this->delivered(ShipmentStatus::PartiallyDelivered, 30_000);
        $this->delivered();                                          // كما طُلب: لا تنتظر شيئاً

        $settled = $this->delivered(collected: 40_000);
        Tenancy::runFor($this->company, fn () => $settled->forceFill(['merchant_settled_at' => now()])->save());

        $this->assertSame([$changed->number, $partial->number], $this->waiting());

        $this->actingAs($this->owner)->get($this->host().'/shipments/stages')->assertOk()
            ->assertSee('انتظار موافقة التسليم');

        // في القائمة زرّ الاعتماد لمن يؤكّد المبالغ، وفي غيرها لا
        $this->actingAs($this->owner)->get($this->host().'/shipments/stages?stage=awaiting_approval')->assertOk()
            ->assertSee($changed->number)->assertSee($partial->number)
            ->assertSee('data-bulk-approve', false);
        $this->actingAs($this->owner)->get($this->host().'/shipments/stages?stage=delivered')->assertOk()
            ->assertDontSee('data-bulk-approve', false);

        // وصفحة الشحنة تقول إنها تنتظر
        $this->actingAs($this->owner)->get($this->host()."/shipments/{$changed->id}")->assertOk()
            ->assertSee('بانتظار موافقة التسليم');
    }

    public function test_approving_locks_the_amounts_the_courier_recorded_and_skips_the_rest(): void
    {
        $changed = $this->delivered(collected: 45_000);
        $partial = $this->delivered(ShipmentStatus::PartiallyDelivered, 30_000);
        $asOrdered = $this->delivered();

        $entries = Tenancy::runFor($this->company, fn () => Transaction::count());
        $cash = (int) $this->courier->fresh()->cash_in_hand;

        $this->actingAs($this->owner)->post($this->host().'/shipments/approve-delivery', [
            'shipment_ids' => [$changed->id, $partial->id, $asOrdered->id],
        ])->assertSessionHas('success', fn (string $m) => str_contains($m, 'اعتُمد تسليم شحنتان')
            && str_contains($m, $asOrdered->number.' (ليست بانتظار الموافقة)'));

        foreach ([[$changed, 45_000], [$partial, 30_000]] as [$shipment, $amount]) {
            $fresh = $shipment->fresh();
            $this->assertTrue($fresh->amount_confirmed);
            $this->assertSame($amount, (int) $fresh->collected_amount);
            $this->assertSame($this->owner->id, (int) $fresh->amount_confirmed_by_user_id);
        }
        $this->assertFalse($asOrdered->fresh()->amount_confirmed);

        // المبلغ كما سُجّل: لا قيدٌ جديد في الدفتر ولا تغيّر في نقد المندوب
        $this->assertSame($entries, Tenancy::runFor($this->company, fn () => Transaction::count()));
        $this->assertSame($cash, (int) $this->courier->fresh()->cash_in_hand);

        Tenancy::runFor($this->company, fn () => $this->assertSame('اعتماد التسليم',
            ShipmentEvent::where('shipment_id', $changed->id)->where('event_type', 'amount_confirmed')->sole()->note));

        $this->assertSame([], $this->waiting());

        // وما اعتُمد لا يُعتمد ثانيةً
        $this->actingAs($this->owner)->post($this->host().'/shipments/approve-delivery', ['shipment_ids' => [$changed->id]])
            ->assertSessionHasErrors(['shipment_ids' => 'لم يُعتمد تسليم أيّ شحنة. تُخطّيت شحنة واحدة: '.$changed->number.' (ليست بانتظار الموافقة).']);
    }

    public function test_approving_everything_in_the_stage_checks_the_count_the_clerk_saw(): void
    {
        $this->delivered(collected: 45_000);
        $this->delivered(collected: 44_000);

        $this->actingAs($this->owner)->post($this->host().'/shipments/approve-delivery', [
            'all' => 1, 'expected' => 1, 'filters' => ['stage' => 'awaiting_approval'],
        ])->assertSessionHasErrors(['shipment_ids' => 'تغيّرت القائمة منذ فتحتها: كانت 1 وصارت 2. راجعها ثم أعد التحديث.']);
        $this->assertCount(2, $this->waiting());

        $this->actingAs($this->owner)->post($this->host().'/shipments/approve-delivery', [
            'all' => 1, 'expected' => 2, 'filters' => ['stage' => 'awaiting_approval'],
        ])->assertSessionHasNoErrors();
        $this->assertSame([], $this->waiting());
    }

    public function test_approval_needs_the_confirm_amount_permission(): void
    {
        $changed = $this->delivered(collected: 45_000);
        $operations = $this->makeUser($this->company, UserRole::Operations);

        $this->actingAs($operations)->get($this->host().'/shipments/stages?stage=awaiting_approval')->assertOk()
            ->assertSee($changed->number)
            ->assertDontSee('data-bulk-approve', false);

        $this->actingAs($operations)->post($this->host().'/shipments/approve-delivery', ['shipment_ids' => [$changed->id]])
            ->assertForbidden();
        $this->assertFalse($changed->fresh()->amount_confirmed);
    }
}
