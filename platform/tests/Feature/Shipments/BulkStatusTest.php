<?php

namespace Tests\Feature\Shipments;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Courier;
use App\Models\FailureReason;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Support\Permissions\Ability;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تحديث الحالة من القائمة بلا دخول كل شحنة: ما اختاره الموظّف، أو كل ما يطابق
 * بحثه (يومٌ، مندوب، مرحلة) — وكل شحنةٍ تمرّ بالمدخل نفسه كأنها غُيّرت وحدها.
 */
class BulkStatusTest extends TestCase
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
        $this->courier = $this->courier('C1', 'أحمد الساعدي');
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function courier(string $code, string $name): Courier
    {
        return Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => $code, 'name' => $name, 'phone' => $this->phoneFrom($code, '0772'),
            'type' => 'delivery', 'status' => 'active', 'commission_per_delivery' => 1500,
        ]));
    }

    /** @param  list<ShipmentStatus>  $statuses */
    private function shipment(array $statuses = [], ?Courier $courier = null, ?Merchant $merchant = null): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($statuses, $courier, $merchant) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id'     => ($merchant ?? $this->merchant)->id,
                'recipient_phone' => '07801234567',
                'governorate_id'  => $this->baghdad()->id,
                'city_id'         => $this->area(),
                'cod_amount'      => 50_000,
            ], $this->owner);

            foreach ($statuses as $status) {
                app(ChangeShipmentStatus::class)->handle($shipment->refresh(), $status, $this->owner, [
                    'courier_id' => $status === ShipmentStatus::OutForDelivery ? ($courier ?? $this->courier)->id : null,
                ]);
            }

            return $shipment->refresh();
        });
    }

    private function out(?Courier $courier = null): Shipment
    {
        return $this->shipment([ShipmentStatus::PickedUp, ShipmentStatus::AtHub, ShipmentStatus::OutForDelivery], $courier);
    }

    private function fresh(Shipment $shipment): Shipment
    {
        return Tenancy::runFor($this->company, fn () => $shipment->fresh());
    }

    private function bulk(array $data, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->post($this->host().'/shipments/bulk-status', $data);
    }

    public function test_the_chosen_shipments_become_delivered_with_their_full_amount_and_the_rest_are_skipped(): void
    {
        $first = $this->out();
        $second = $this->out();
        $onShelf = $this->shipment([ShipmentStatus::PickedUp, ShipmentStatus::AtHub]);

        $this->bulk(['status' => 'delivered', 'shipment_ids' => [$first->id, $second->id, $onShelf->id]])
            ->assertSessionHas('success', 'حُدّثت شحنتان إلى «واصل». تُخطّيت شحنة واحدة: '.$onShelf->number.' (بالمخزن).');

        foreach ([$first, $second] as $shipment) {
            $fresh = $this->fresh($shipment);
            $this->assertSame(ShipmentStatus::Delivered, $fresh->status);
            $this->assertSame(50_000, $fresh->collected_amount);
        }

        // والمال كما لو سُلّمت كلٌّ وحدها: المبلغ بذمّة المندوب، وفي سجلّها «تحديث جماعي»
        $this->assertSame(100_000, (int) Tenancy::runFor($this->company, fn () => $this->courier->fresh()->cash_in_hand));
        $this->assertSame('تحديث جماعي', Tenancy::runFor($this->company, fn () => ShipmentEvent::where('shipment_id', $first->id)
            ->where('to_status', 'delivered')->value('note')));
        $this->assertSame(ShipmentStatus::AtHub, $this->fresh($onShelf)->status);
    }

    public function test_one_shipment_is_updated_from_the_list_without_opening_it(): void
    {
        $shipment = $this->shipment();

        $this->bulk(['status' => 'picked_up', 'shipment_ids' => [$shipment->id]])
            ->assertSessionHas('success', 'حُدّثت شحنة واحدة إلى «استلمه المندوب».');

        $this->assertSame(ShipmentStatus::PickedUp, $this->fresh($shipment)->status);
    }

    public function test_not_delivered_needs_a_reason_and_the_note_the_reason_asks_for(): void
    {
        $shipment = $this->out();
        [$plain, $withNote] = Tenancy::runFor($this->company, fn () => [
            FailureReason::where('code', 'no_answer')->value('id'),
            FailureReason::where('code', 'customer_refused')->value('id'),
        ]);

        $this->bulk(['status' => 'failed_attempt', 'shipment_ids' => [$shipment->id]])
            ->assertSessionHasErrors(['failure_reason_id' => 'اختر سبب عدم التسليم.']);

        $this->bulk(['status' => 'failed_attempt', 'shipment_ids' => [$shipment->id], 'failure_reason_id' => $withNote])
            ->assertSessionHasErrors('note');

        $this->assertSame(ShipmentStatus::OutForDelivery, $this->fresh($shipment)->status);

        $this->bulk(['status' => 'failed_attempt', 'shipment_ids' => [$shipment->id], 'failure_reason_id' => $plain])
            ->assertSessionHas('success');

        $fresh = $this->fresh($shipment);
        $this->assertSame(ShipmentStatus::FailedAttempt, $fresh->status);
        $this->assertSame($plain, $fresh->last_failure_reason_id);
        $this->assertSame(1, $fresh->attempts_count);
    }

    public function test_out_for_delivery_assigns_the_courier_like_the_morning_dispatch(): void
    {
        $new = $this->shipment();
        $onShelf = $this->shipment([ShipmentStatus::PickedUp, ShipmentStatus::AtHub]);

        $this->bulk(['status' => 'out_for_delivery', 'shipment_ids' => [$new->id, $onShelf->id]])
            ->assertSessionHasErrors(['courier_id' => 'اختر المندوب.']);

        $this->bulk(['status' => 'out_for_delivery', 'shipment_ids' => [$new->id, $onShelf->id], 'courier_id' => $this->courier->id])
            ->assertSessionHas('success', 'أُسندت شحنتان إلى أحمد الساعدي (استُلمت من التاجر أوّلاً: '.$new->number.').');

        foreach ([$new, $onShelf] as $shipment) {
            $this->assertSame(ShipmentStatus::OutForDelivery, $this->fresh($shipment)->status);
            $this->assertSame($this->courier->id, $this->fresh($shipment)->delivery_courier_id);
        }
    }

    /** «بالمخزن» كالمسح عند الباب: ما لم يُستلم يُستلم أوّلاً، ويدخل مخزن الموظّف */
    public function test_at_hub_receives_what_was_not_received_yet(): void
    {
        $new = $this->shipment();
        $back = $this->out();

        $this->bulk(['status' => 'at_hub', 'shipment_ids' => [$new->id, $back->id]])
            ->assertSessionHas('success', 'حُدّثت شحنتان إلى «بالمخزن».');

        $this->assertSame(ShipmentStatus::AtHub, $this->fresh($new)->status);
        $this->assertNotNull($this->fresh($new)->picked_up_at);
        $this->assertSame(ShipmentStatus::AtHub, $this->fresh($back)->status);
    }

    public function test_each_target_needs_its_own_permission(): void
    {
        $shipment = $this->out();

        // يغيّر الحالة ولا يُسند: «واصل» له، و«مع مندوب» ليس له
        $follower = $this->staff([Ability::SHIPMENTS_VIEW, Ability::SHIPMENTS_STATUS]);
        $this->bulk(['status' => 'out_for_delivery', 'shipment_ids' => [$shipment->id], 'courier_id' => $this->courier->id], $follower)
            ->assertForbidden();

        // يُسند ولا يغيّر الحالة
        $dispatcher = $this->staff([Ability::SHIPMENTS_VIEW, Ability::SHIPMENTS_ASSIGN]);
        $this->bulk(['status' => 'delivered', 'shipment_ids' => [$shipment->id]], $dispatcher)->assertForbidden();
        $this->assertSame(ShipmentStatus::OutForDelivery, $this->fresh($shipment)->status);

        $this->bulk(['status' => 'delivered', 'shipment_ids' => [$shipment->id]], $follower)->assertSessionHas('success');

        // وما لا يُحدَّث بالجملة يُرفض برسالة: الواصل الجزئي يحتاج مبلغ كل شحنة
        $this->bulk(['status' => 'partially_delivered', 'shipment_ids' => [$shipment->id]])
            ->assertSessionHasErrors(['status' => 'هذه الحالة لا تُحدَّث بالجملة — غيّرها من صفحة الشحنة.']);
    }

    /** «تحديث الكل»: البحث نفسه يُعاد في الخادم، لا الصفحة المعروضة وحدها */
    public function test_update_all_takes_every_match_of_the_search_and_nothing_else(): void
    {
        $other = $this->courier('C2', 'علي الكعبي');
        $his = [$this->out(), $this->out(), $this->out()];
        $notHis = $this->out($other);

        $this->bulk([
            'status' => 'delivered', 'all' => 1, 'expected' => 3,
            'filters' => ['status' => 'out_for_delivery', 'courier_id' => (string) $this->courier->id],
        ])->assertSessionHas('success', 'حُدّثت 3 شحنات إلى «واصل».');

        foreach ($his as $shipment) {
            $this->assertSame(ShipmentStatus::Delivered, $this->fresh($shipment)->status);
        }
        $this->assertSame(ShipmentStatus::OutForDelivery, $this->fresh($notHis)->status);
    }

    /** شحنةٌ دخلت القائمة بعد فتحها لا تُعلَن «واصل» وهو لم يرَها */
    public function test_update_all_refuses_a_list_that_changed_since_it_was_opened(): void
    {
        $seen = $this->out();
        $arrivedLater = $this->out();

        $this->bulk([
            'status' => 'delivered', 'all' => 1, 'expected' => 1,
            'filters' => ['status' => 'out_for_delivery'],
        ])->assertSessionHasErrors(['shipment_ids' => 'تغيّرت القائمة منذ فتحتها: كانت 1 وصارت 2. راجعها ثم أعد التحديث.']);

        $this->assertSame(ShipmentStatus::OutForDelivery, $this->fresh($seen)->status);
        $this->assertSame(ShipmentStatus::OutForDelivery, $this->fresh($arrivedLater)->status);
    }

    /** «حسب اليوم»: شحنات يومٍ واحد بضغطة، ثم «تحديث الكل» لها وحدها */
    public function test_a_day_of_shipments_is_updated_alone(): void
    {
        $this->travelTo(now()->subDay());
        $yesterday = $this->shipment();
        $this->travelBack();
        $today = [$this->shipment(), $this->shipment()];
        $day = today()->toDateString();

        $page = $this->actingAs($this->owner)->get($this->host().'/shipments?from='.$day.'&to='.$day)->assertOk();
        $page->assertSee($today[0]->number)->assertDontSee($yesterday->number)->assertSee('تحديث الكل (2)');

        $this->bulk([
            'status' => 'picked_up', 'all' => 1, 'expected' => 2, 'filters' => ['from' => $day, 'to' => $day],
        ])->assertSessionHas('success', 'حُدّثت شحنتان إلى «استلمه المندوب».');

        $this->assertSame(ShipmentStatus::PickedUp, $this->fresh($today[0])->status);
        $this->assertSame(ShipmentStatus::PickedUp, $this->fresh($today[1])->status);
        $this->assertSame(ShipmentStatus::Created, $this->fresh($yesterday)->status);
    }

    public function test_a_branch_clerk_updates_only_what_its_branch_sees(): void
    {
        $basra = Tenancy::runFor($this->company, fn () => Branch::create(['code' => 'B2', 'name' => 'فرع البصرة']));
        $theirMerchant = $this->makeMerchant($this->company, 'M0002');
        Tenancy::runFor($this->company, fn () => $theirMerchant->forceFill(['branch_id' => $basra->id])->save());

        $ours = $this->shipment();
        $theirs = $this->shipment([], null, $theirMerchant);

        $clerk = $this->makeUser($this->company, UserRole::Operations);
        Tenancy::runFor($this->company, fn () => $clerk->forceFill(['branch_id' => $basra->id])->save());

        // رقم شحنة فرعٍ آخر في الطلب لا يمسّها
        $this->bulk(['status' => 'picked_up', 'shipment_ids' => [$ours->id, $theirs->id]], $clerk)
            ->assertSessionHas('success', 'حُدّثت شحنة واحدة إلى «استلمه المندوب».');
        $this->assertSame(ShipmentStatus::Created, $this->fresh($ours)->status);
        $this->assertSame(ShipmentStatus::PickedUp, $this->fresh($theirs)->status);

        // و«الكل» كلّه في فرعه
        $this->bulk(['status' => 'cancelled', 'all' => 1, 'expected' => 1, 'filters' => ['status' => 'picked_up']], $clerk)
            ->assertSessionHas('success');
        $this->assertSame(ShipmentStatus::Cancelled, $this->fresh($theirs)->status);
        $this->assertSame(ShipmentStatus::Created, $this->fresh($ours)->status);
    }

    public function test_the_list_offers_the_bar_the_day_chips_and_update_all(): void
    {
        $this->out();
        $this->shipment();

        $this->actingAs($this->owner)->get($this->host().'/shipments')->assertOk()
            ->assertSee('data-bulk-bar', false)
            ->assertSee('data-status="out_for_delivery"', false)
            ->assertSee('data-status="created"', false)
            ->assertSee('واصل (المبلغ كاملاً)')
            ->assertSee('قيد التوصيل — مع مندوب')
            ->assertSee('تحديث الكل (2)')
            ->assertSeeInOrder(['أُنشئت:', 'كل الأيام', 'اليوم', 'أمس'])
            // عدد كل حالٍ في البحث كلّه: منه يُحسب ما سيتحرّك في «الكل»
            ->assertSee('data-counts="{&quot;created&quot;:1,&quot;out_for_delivery&quot;:1}"', false);

        // في «كل مراحل النقل» اليوم يوم دخول المرحلة
        $this->actingAs($this->owner)->get($this->host().'/shipments/stages?stage=out_for_delivery')->assertOk()
            ->assertSee('دخلت المرحلة:')
            ->assertSee('stage_from='.today()->toDateString(), false)
            ->assertSee('تحديث الكل (1)');

        // من لا يغيّر الحالة ولا يُسند: لا حالات ولا «تحديث الكل» — والطباعة باقية
        $reader = $this->staff([Ability::SHIPMENTS_VIEW]);
        $this->actingAs($reader)->get($this->host().'/shipments')->assertOk()
            ->assertSee('data-bulk-bar', false)
            ->assertDontSee('data-bulk-status', false)
            ->assertDontSee('تحديث الكل')
            ->assertSee('طباعة الوصولات');
    }

    private function staff(array $abilities): User
    {
        return Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'موظّف', 'phone' => $this->phoneFrom(implode(',', $abilities)), 'password' => 'password',
            'role' => UserRole::Operations, 'permissions' => $abilities, 'is_active' => true,
        ]));
    }
}
