<?php

namespace Tests\Feature\Transport;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Actions\Transport\BagShipments;
use App\Actions\Transport\RunManifest;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Bag;
use App\Models\Company;
use App\Models\Hub;
use App\Models\Manifest;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * النقل بين المراكز.
 *
 * الكيس اختصار مسح، والكشف ورقة مسؤولية. وقيمة الاثنين تظهر يوم يختفي
 * كيس: إمّا أن يُعرف مَن حمّله ومَن استلمه ومتى، أو يبقى ثلاثون طرداً
 * «قيد النقل» إلى الأبد ولا أحد يبحث عنها.
 */
class TransportTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $staff;

    private Hub $baghdad;

    private Hub $basra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->staff = $this->makeUser($this->company);

        [$this->baghdad, $this->basra] = Tenancy::runFor($this->company, fn () => [
            Hub::create(['code' => 'BGD', 'name' => 'مركز بغداد', 'type' => 'main', 'is_active' => true]),
            Hub::create(['code' => 'BSR', 'name' => 'مركز البصرة', 'type' => 'branch', 'is_active' => true]),
        ]);
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function bagger(): BagShipments
    {
        return app(BagShipments::class);
    }

    private function runner(): RunManifest
    {
        return app(RunManifest::class);
    }

    /** شحنة في المخزن جاهزة للتكييس. */
    private function atHub(string $recipient = 'علي حسين'): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($recipient) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id'     => $this->merchant->id,
                'recipient_name'  => $recipient,
                'recipient_phone' => '07801234567',
                'governorate_id'  => $this->baghdad()->id,
                'address'         => 'بغداد',
                'landmark'        => 'قرب الجامع',
                'cod_amount'      => 50_000,
            ], $this->staff);

            $change = app(ChangeShipmentStatus::class);
            $change->handle($shipment->refresh(), ShipmentStatus::PickedUp, $this->staff);
            $change->handle($shipment->refresh(), ShipmentStatus::AtHub, $this->staff,
                ['hub_id' => $this->baghdad->id]);

            return $shipment->refresh();
        });
    }

    private function bag(): Bag
    {
        return Tenancy::runFor($this->company, fn () => $this->bagger()
            ->create($this->baghdad, $this->basra, $this->staff));
    }

    /** كيس مختوم فيه شحنتان. */
    private function sealedBag(): array
    {
        $bag = $this->bag();
        $one = $this->atHub('أحمد');
        $two = $this->atHub('سارة');

        Tenancy::runFor($this->company, function () use ($bag, $one, $two) {
            $this->bagger()->add($bag, [$one->number, $two->number], $this->staff);
            $this->bagger()->seal($bag->refresh(), $this->staff);
        });

        return [$bag->refresh(), $one, $two];
    }

    // ── التكييس ─────────────────────────────────────────────────────

    public function test_bagging_does_not_move_the_shipment_yet(): void
    {
        $bag = $this->bag();
        $shipment = $this->atHub();

        Tenancy::runFor($this->company, function () use ($bag, $shipment) {
            $this->bagger()->add($bag, [$shipment->number], $this->staff);

            $fresh = $shipment->refresh();

            // الطرد على رفّ المخزن حتى تتحرّك السيارة
            $this->assertSame(ShipmentStatus::AtHub, $fresh->status);
            $this->assertSame($bag->id, (int) $fresh->current_bag_id);
            $this->assertSame(1, (int) $bag->refresh()->shipments_count);
        });
    }

    public function test_a_shipment_is_found_by_its_barcode_too(): void
    {
        $bag = $this->bag();
        $shipment = $this->atHub();

        Tenancy::runFor($this->company, function () use ($bag, $shipment) {
            $result = $this->bagger()->add($bag, [$shipment->barcode], $this->staff);

            $this->assertCount(1, $result['added']);
            $this->assertSame([], $result['errors']);
        });
    }

    public function test_every_rejected_number_says_why(): void
    {
        $bag = $this->bag();
        $other = $this->bag();
        $mine = $this->atHub();
        $taken = $this->atHub();

        Tenancy::runFor($this->company, function () use ($bag, $other, $mine, $taken) {
            $this->bagger()->add($other, [$taken->number], $this->staff);

            $result = $this->bagger()->add($bag, [$mine->number, $taken->number, '999999'], $this->staff);

            $this->assertCount(1, $result['added']);
            $this->assertArrayHasKey($taken->number, $result['errors']);
            $this->assertStringContainsString($other->code, $result['errors'][$taken->number]);
            $this->assertSame('لا وصل بهذا الرقم.', $result['errors']['999999']);
        });
    }

    public function test_a_delivered_shipment_is_not_bagged(): void
    {
        $bag = $this->bag();
        $shipment = $this->atHub();

        Tenancy::runFor($this->company, function () use ($bag, $shipment) {
            app(ChangeShipmentStatus::class)->handle($shipment, ShipmentStatus::OutForDelivery, $this->staff);
            app(ChangeShipmentStatus::class)->handle($shipment->refresh(), ShipmentStatus::Delivered, $this->staff);

            $result = $this->bagger()->add($bag, [$shipment->number], $this->staff);

            $this->assertCount(0, $result['added']);
            $this->assertStringContainsString('تم التسليم', $result['errors'][$shipment->number]);
        });
    }

    public function test_the_same_number_twice_in_one_scan_counts_once(): void
    {
        $bag = $this->bag();
        $shipment = $this->atHub();

        Tenancy::runFor($this->company, function () use ($bag, $shipment) {
            $result = $this->bagger()->add($bag, [$shipment->number, $shipment->number], $this->staff);

            $this->assertCount(1, $result['added']);
            $this->assertSame(1, (int) $bag->refresh()->shipments_count);
        });
    }

    public function test_an_empty_bag_is_not_sealed(): void
    {
        $bag = $this->bag();

        $this->expectException(ValidationException::class);

        Tenancy::runFor($this->company, fn () => $this->bagger()->seal($bag, $this->staff));
    }

    public function test_a_sealed_bag_accepts_nothing_more(): void
    {
        [$bag] = $this->sealedBag();
        $late = $this->atHub('متأخّر');

        $this->expectException(ValidationException::class);

        Tenancy::runFor($this->company, fn () => $this->bagger()->add($bag, [$late->number], $this->staff));
    }

    public function test_removing_from_an_open_bag_frees_the_shipment(): void
    {
        $bag = $this->bag();
        $shipment = $this->atHub();

        Tenancy::runFor($this->company, function () use ($bag, $shipment) {
            $this->bagger()->add($bag, [$shipment->number], $this->staff);
            $this->bagger()->remove($bag->refresh(), $shipment->refresh(), $this->staff);

            $this->assertNull($shipment->refresh()->current_bag_id);
            $this->assertSame(0, (int) $bag->refresh()->shipments_count);
        });
    }

    // ── الكشف ───────────────────────────────────────────────────────

    public function test_only_a_sealed_bag_is_loaded(): void
    {
        $bag = $this->bag();
        $shipment = $this->atHub();

        $this->expectException(ValidationException::class);

        Tenancy::runFor($this->company, function () use ($bag, $shipment) {
            $this->bagger()->add($bag, [$shipment->number], $this->staff);
            $manifest = $this->runner()->create($this->baghdad, $this->basra, [], $this->staff);

            $this->runner()->load($manifest, $bag->refresh(), $this->staff);
        });
    }

    public function test_a_bag_bound_elsewhere_is_refused(): void
    {
        [$bag] = $this->sealedBag();

        $mosul = Tenancy::runFor($this->company, fn () => Hub::create([
            'code' => 'NIN', 'name' => 'مركز الموصل', 'type' => 'branch', 'is_active' => true,
        ]));

        $this->expectException(ValidationException::class);

        Tenancy::runFor($this->company, function () use ($bag, $mosul) {
            $manifest = $this->runner()->create($this->baghdad, $mosul, [], $this->staff);
            $this->runner()->load($manifest, $bag, $this->staff);
        });
    }

    public function test_an_empty_manifest_is_not_dispatched(): void
    {
        $this->expectException(ValidationException::class);

        Tenancy::runFor($this->company, function () {
            $manifest = $this->runner()->create($this->baghdad, $this->basra, [], $this->staff);
            $this->runner()->dispatch($manifest, $this->staff);
        });
    }

    public function test_dispatching_puts_the_whole_load_in_transit(): void
    {
        [$bag, $one, $two] = $this->sealedBag();

        Tenancy::runFor($this->company, function () use ($bag, $one, $two) {
            $manifest = $this->runner()->create($this->baghdad, $this->basra,
                ['driver_name' => 'كريم', 'vehicle_number' => '12345 بغداد'], $this->staff);
            $this->runner()->load($manifest, $bag, $this->staff);
            $this->runner()->dispatch($manifest->refresh(), $this->staff);

            $this->assertSame('dispatched', $manifest->refresh()->status);
            $this->assertNotNull($manifest->departed_at);
            $this->assertSame('in_transit', $bag->refresh()->status);
            $this->assertSame(ShipmentStatus::InTransit, $one->refresh()->status);
            $this->assertSame(ShipmentStatus::InTransit, $two->refresh()->status);
            $this->assertSame(2, (int) $manifest->shipments_count);
        });
    }

    public function test_a_dispatched_manifest_is_not_edited(): void
    {
        [$bag] = $this->sealedBag();

        $this->expectException(ValidationException::class);

        Tenancy::runFor($this->company, function () use ($bag) {
            $manifest = $this->runner()->create($this->baghdad, $this->basra, [], $this->staff);
            $this->runner()->load($manifest, $bag, $this->staff);
            $this->runner()->dispatch($manifest->refresh(), $this->staff);

            $this->runner()->unload($manifest->refresh(), $bag);
        });
    }

    // ── الوصول ──────────────────────────────────────────────────────

    private function dispatched(): array
    {
        [$bag, $one, $two] = $this->sealedBag();

        $manifest = Tenancy::runFor($this->company, function () use ($bag) {
            $manifest = $this->runner()->create($this->baghdad, $this->basra, [], $this->staff);
            $this->runner()->load($manifest, $bag, $this->staff);

            return $this->runner()->dispatch($manifest->refresh(), $this->staff);
        });

        return [$manifest, $bag->refresh(), $one, $two];
    }

    public function test_receiving_a_bag_marks_it_arrived_but_not_yet_opened(): void
    {
        [$manifest, $bag, $one] = $this->dispatched();

        Tenancy::runFor($this->company, function () use ($manifest, $bag, $one) {
            $this->runner()->receive($manifest, [$bag->id], $this->staff);

            $this->assertSame('arrived', $manifest->refresh()->status);
            $this->assertSame('received', $bag->refresh()->status);
            $this->assertNotNull($bag->received_at);

            // لم يُفتح الكيس بعد، فالشحنة ما زالت قيد النقل
            $this->assertSame(ShipmentStatus::InTransit, $one->refresh()->status);
        });
    }

    public function test_opening_the_bag_puts_its_shipments_in_the_destination_hub(): void
    {
        [$manifest, $bag, $one, $two] = $this->dispatched();

        Tenancy::runFor($this->company, function () use ($manifest, $bag, $one, $two) {
            $this->runner()->receive($manifest, [$bag->id], $this->staff);
            $this->bagger()->open($bag->refresh(), $this->staff);

            foreach ([$one, $two] as $shipment) {
                $fresh = $shipment->refresh();
                $this->assertSame(ShipmentStatus::AtHub, $fresh->status);
                $this->assertSame($this->basra->id, (int) $fresh->hub_id);
                $this->assertNull($fresh->current_bag_id);
            }

            $this->assertSame('opened', $bag->refresh()->status);
        });
    }

    /**
     * الراجع يعبر بين الفروع راجعاً ويصل راجعاً.
     *
     * «قيد الإرجاع ← في المخزن» انتقالٌ مشروع — لإعادة المحاولة حين
     * يطلبها التاجر. فكان فتح الكيس يحوّل كل ما فيه إلى «في المخزن»
     * بلا تمييز، فيعود الراجع شحنةً عاديّة: يختفي من شاشة تسليم الراجع،
     * ولا تُقيَّد أجرته أبداً، ويُمكن أن يخرج مع مندوبٍ مرّةً أخرى إلى
     * الزبون الذي رفضه.
     */
    public function test_a_return_crossing_branches_arrives_still_a_return(): void
    {
        $shipment = $this->atHub('زبون رفض الطلب');

        Tenancy::runFor($this->company, function () use ($shipment) {
            $change = app(ChangeShipmentStatus::class);
            $courier = \App\Models\Courier::create([
                'code' => 'C1', 'name' => 'مندوب', 'phone' => '07720000009',
                'type' => 'delivery', 'status' => 'active',
            ]);
            $reason = \App\Models\FailureReason::where('code', 'no_answer')->firstOrFail();

            $change->handle($shipment->refresh(), ShipmentStatus::OutForDelivery, $this->staff, ['courier_id' => $courier->id]);
            $change->handle($shipment->refresh(), ShipmentStatus::FailedAttempt, $this->staff, ['failure_reason_id' => $reason->id]);
            $change->handle($shipment->refresh(), ShipmentStatus::Returning, $this->staff);
            app(\App\Actions\Returns\ReceiveReturns::class)->handle([$shipment->id], $this->staff);
        });

        $bag = $this->bag();

        Tenancy::runFor($this->company, function () use ($bag, $shipment) {
            $this->bagger()->add($bag, [$shipment->number], $this->staff);
            $this->bagger()->seal($bag->refresh(), $this->staff);

            $manifest = $this->runner()->create($this->baghdad, $this->basra, [], $this->staff);
            $this->runner()->load($manifest, $bag->refresh(), $this->staff);
            $this->runner()->dispatch($manifest->refresh(), $this->staff);
            $this->runner()->receive($manifest->refresh(), [$bag->id], $this->staff);
            $this->bagger()->open($bag->refresh(), $this->staff);

            $fresh = $shipment->refresh();

            $this->assertSame(ShipmentStatus::Returning, $fresh->status, 'الراجع عاد شحنةً عاديّة بفتح الكيس.');
            // وصار مكانه مركز الوصول، فيراه فرع التاجر جاهزاً للتسليم
            $this->assertSame($this->basra->id, (int) $fresh->hub_id);
            $this->assertNull($fresh->current_bag_id);
            $this->assertNotNull($fresh->return_received_at);
        });
    }

    public function test_an_unopened_bag_cannot_be_opened_before_it_arrives(): void
    {
        [, $bag] = $this->dispatched();

        $this->expectException(ValidationException::class);

        Tenancy::runFor($this->company, fn () => $this->bagger()->open($bag, $this->staff));
    }

    public function test_a_bag_left_off_the_receipt_is_recorded_as_missing_on_every_shipment(): void
    {
        [$manifest, $bag, $one, $two] = $this->dispatched();

        Tenancy::runFor($this->company, function () use ($manifest, $bag, $one, $two) {
            // وصل الكشف ولم يصل الكيس
            $this->runner()->receive($manifest, [], $this->staff);

            $this->assertSame(1, $manifest->refresh()->missingBags());
            $this->assertSame('in_transit', $bag->refresh()->status, 'الكيس المفقود لم يصل');

            foreach ([$one, $two] as $shipment) {
                $this->assertDatabaseHas('shipment_events', [
                    'shipment_id' => $shipment->id,
                    'event_type'  => 'bag_missing',
                ]);
            }

            $event = ShipmentEvent::where('shipment_id', $one->id)
                ->where('event_type', 'bag_missing')->firstOrFail();

            $this->assertStringContainsString($bag->code, $event->note);
            $this->assertStringContainsString($manifest->code, $event->note);
        });
    }

    public function test_a_partly_arrived_manifest_separates_the_two_bags(): void
    {
        [$manifest, $arrived] = $this->dispatched();

        $lost = Tenancy::runFor($this->company, function () use ($manifest) {
            $bag = $this->bagger()->create($this->baghdad, $this->basra, $this->staff);

            return $bag;
        });

        $shipment = $this->atHub('ضائع');

        Tenancy::runFor($this->company, function () use ($manifest, $arrived, $lost, $shipment) {
            // كشف ثانٍ فيه الكيس الذي سيُفقَد
            $this->bagger()->add($lost, [$shipment->number], $this->staff);
            $this->bagger()->seal($lost->refresh(), $this->staff);

            $second = $this->runner()->create($this->baghdad, $this->basra, [], $this->staff);
            $this->runner()->load($second, $lost->refresh(), $this->staff);
            $this->runner()->dispatch($second->refresh(), $this->staff);

            $this->runner()->receive($manifest, [$arrived->id], $this->staff);
            $this->runner()->receive($second->refresh(), [], $this->staff);

            $this->assertSame(0, $manifest->refresh()->missingBags());
            $this->assertSame(1, $second->refresh()->missingBags());
        });
    }

    // ── الشاشات ─────────────────────────────────────────────────────

    public function test_staff_scan_receipts_into_a_bag_from_the_screen(): void
    {
        $bag = $this->bag();
        $one = $this->atHub('أحمد');
        $two = $this->atHub('سارة');

        $this->actingAs($this->staff)
            ->post($this->host()."/bags/{$bag->id}/add", [
                'numbers' => "{$one->number}\n{$two->number}\n999999",
            ])
            ->assertRedirect()
            ->assertSessionHas('success')
            // الرقم المجهول يُسمّى تنبيهاً، لا بوصفه فشل حفظ
            ->assertSessionHasNoErrors()
            ->assertSessionHas('rejected', fn (array $r) => array_key_exists('999999', $r));

        Tenancy::runFor($this->company, fn () => $this->assertSame(2, (int) $bag->refresh()->shipments_count));
    }

    public function test_the_inbound_screen_lists_what_is_on_the_road(): void
    {
        [$manifest] = $this->dispatched();

        $this->actingAs($this->staff)
            ->get($this->host().'/manifests/inbound')
            ->assertOk()
            ->assertSee($manifest->code);
    }

    public function test_staff_receive_a_manifest_and_are_told_what_is_missing(): void
    {
        [$manifest] = $this->dispatched();

        $this->actingAs($this->staff)
            ->post($this->host()."/manifests/{$manifest->id}/receive", ['bag_ids' => []])
            ->assertRedirect()
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'مفقودة'));
    }

    // ── الأرشيف والطباعة ────────────────────────────────────────────

    public function test_an_arrived_manifest_is_archived_as_sent_from_its_origin_and_received_at_its_destination(): void
    {
        [$manifest, $bag] = $this->dispatched();

        Tenancy::runFor($this->company, fn () => $this->runner()->receive($manifest, [$bag->id], $this->staff));

        $out = $this->actingAs($this->staff)
            ->get($this->host().'/manifests/archive?hub_id='.$this->baghdad->id.'&direction=out')
            ->assertOk()->viewData('manifests');
        $in = $this->actingAs($this->staff)
            ->get($this->host().'/manifests/archive?hub_id='.$this->basra->id.'&direction=in')
            ->viewData('manifests');
        $wrongWay = $this->actingAs($this->staff)
            ->get($this->host().'/manifests/archive?hub_id='.$this->baghdad->id.'&direction=in')
            ->viewData('manifests');

        $this->assertSame([$manifest->id], $out->pluck('id')->all());
        $this->assertSame([$manifest->id], $in->pluck('id')->all());
        $this->assertCount(0, $wrongWay);
    }

    /** الأرشيف للمُقفَل: ما زال في الطريق مكانه شاشة الوارد لا الأرشيف. */
    public function test_a_manifest_still_on_the_road_is_not_archived(): void
    {
        $this->dispatched();

        $this->assertCount(0, $this->actingAs($this->staff)
            ->get($this->host().'/manifests/archive?hub_id='.$this->baghdad->id.'&direction=out')
            ->viewData('manifests'));
    }

    public function test_the_archive_can_show_only_manifests_that_lost_a_bag(): void
    {
        [$manifest] = $this->dispatched();

        // استُلم بلا كيسه الوحيد: نقص
        Tenancy::runFor($this->company, fn () => $this->runner()->receive($manifest, [], $this->staff));

        $rows = $this->actingAs($this->staff)
            ->get($this->host().'/manifests/archive?hub_id='.$this->baghdad->id.'&direction=out&missing=1')
            ->viewData('manifests');

        $this->assertSame([$manifest->id], $rows->pluck('id')->all());
        $this->assertSame(1, (int) $rows->first()->missing_count);
    }

    public function test_the_printed_manifest_lists_every_bag_and_marks_the_missing_one(): void
    {
        [$manifest, $bag] = $this->dispatched();

        Tenancy::runFor($this->company, fn () => $this->runner()->receive($manifest, [], $this->staff));

        $this->actingAs($this->staff)
            ->get($this->host().'/manifests/'.$manifest->id.'/print')
            ->assertOk()
            ->assertSee($bag->code)
            ->assertSee('لم يصل')
            ->assertSee('كيس واحد');
    }

    public function test_a_merchant_login_cannot_reach_the_transport_screens(): void
    {
        $user = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'تاجر', 'phone' => '07790000001', 'password' => 'password',
            'role' => UserRole::Merchant, 'merchant_id' => $this->merchant->id, 'is_active' => true,
        ]));

        $this->actingAs($user)->get($this->host().'/bags')->assertForbidden();
        $this->actingAs($user)->get($this->host().'/manifests')->assertForbidden();
        $this->actingAs($user)->get($this->host().'/manifests/inbound')->assertForbidden();
    }

    public function test_one_company_cannot_open_another_companys_bag(): void
    {
        [$bag] = $this->sealedBag();

        $other = $this->makeCompany('barq', 'البرق');
        $otherStaff = $this->makeUser($other);

        $this->actingAs($otherStaff)
            ->get('http://barq.'.config('zajel.tenant_domain')."/bags/{$bag->id}")
            ->assertNotFound();
    }
}
