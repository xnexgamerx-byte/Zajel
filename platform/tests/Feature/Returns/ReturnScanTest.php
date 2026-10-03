<?php

namespace Tests\Feature\Returns;

use App\Actions\Returns\HandOverReturns;
use App\Actions\Returns\ReceiveReturns;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use App\Support\Tracking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * مراجعة الراجع بالمسح: الطرد في يد الموظّف، يمسح باركوده أو رمز QR الذي على وصله،
 * فيُعلَّم في قائمة الخطوة إن كان منها — وإلّا قيل له أين يذهب به. ومسار الراجع
 * يُرى خطوةً خطوة حتى يصل يد التاجر.
 */
class ReturnScanTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $alpha;

    private Merchant $beta;

    private User $staff;

    private Courier $courier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->alpha = $this->makeMerchant($this->company, 'M0001');
        $this->beta = $this->makeMerchant($this->company, 'M0002');
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

    private function shipment(?Merchant $merchant = null): Shipment
    {
        return Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle([
            'merchant_id'     => ($merchant ?? $this->alpha)->id,
            'recipient_name'  => 'علي حسين',
            'recipient_phone' => '07801234567',
            'governorate_id'  => $this->baghdad()->id,
            'address'         => 'بغداد',
            'landmark'        => 'قرب الجامع',
            'cod_amount'      => 50_000,
        ], $this->staff));
    }

    private function walk(Shipment $shipment, array $path): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($shipment, $path) {
            foreach ($path as $status) {
                app(ChangeShipmentStatus::class)->handle($shipment->refresh(), $status, $this->staff, [
                    'courier_id' => $status === ShipmentStatus::OutForDelivery ? $this->courier->id : null,
                ]);
            }

            return $shipment->refresh();
        });
    }

    /** رفضه زبونه وقُرّر إرجاعه — ما زال بيد المندوب. */
    private function returning(?Merchant $merchant = null): Shipment
    {
        return $this->walk($this->shipment($merchant), [
            ShipmentStatus::PickedUp, ShipmentStatus::AtHub, ShipmentStatus::OutForDelivery,
            ShipmentStatus::FailedAttempt, ShipmentStatus::Returning,
        ]);
    }

    private function receive(Shipment $shipment): Shipment
    {
        Tenancy::runFor($this->company, fn () => app(ReceiveReturns::class)->handle([$shipment->id], $this->staff));

        return Tenancy::runFor($this->company, fn () => $shipment->refresh());
    }

    private function lookup(string $code, array $query): TestResponse
    {
        return $this->actingAs($this->staff)
            ->getJson($this->host().'/returns/lookup?'.http_build_query(['code' => $code] + $query));
    }

    /** ما يكتبه الماسح من رمز QR على وصل الشحنة */
    private function qr(Shipment $shipment, ?Company $company = null): string
    {
        return Tenancy::runFor($company ?? $this->company, fn () => Tracking::url($shipment));
    }

    // ── المسح ───────────────────────────────────────────────────────

    public function test_the_barcode_and_the_label_qr_both_find_a_return_still_with_the_courier(): void
    {
        $shipment = $this->returning();

        foreach ([$shipment->number, $this->qr($shipment)] as $code) {
            $this->lookup($code, ['stage' => 'incoming'])
                ->assertOk()
                ->assertJson(['id' => $shipment->id, 'number' => $shipment->number, 'courier' => 'أحمد الساعدي']);
        }
    }

    public function test_a_qr_from_another_company_is_not_taken_for_ours(): void
    {
        $ours = $this->returning();

        // شركةٌ أخرى على المنصّة: وصلها الأوّل يحمل رقم وصلنا الأوّل نفسه
        $other = $this->makeCompany('najm', 'النجم');
        $merchant = $this->makeMerchant($other, 'M0009');
        $owner = $this->makeUser($other);
        $theirs = Tenancy::runFor($other, fn () => app(CreateShipment::class)->handle([
            'merchant_id' => $merchant->id, 'recipient_name' => 'زبون', 'recipient_phone' => '07801112222',
            'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'landmark' => 'قرب الجسر', 'cod_amount' => 1000,
        ], $owner));

        $this->assertSame($ours->number, $theirs->number);

        $this->lookup($this->qr($theirs, $other), ['stage' => 'incoming'])
            ->assertNotFound()
            ->assertJson(['error' => 'رمز QR هذا ليس لوصلٍ من وصولاتنا.']);
    }

    public function test_incoming_says_where_a_parcel_off_the_list_belongs(): void
    {
        $received = $this->receive($this->returning());
        $undecided = $this->walk($this->shipment(), [
            ShipmentStatus::PickedUp, ShipmentStatus::AtHub, ShipmentStatus::OutForDelivery, ShipmentStatus::FailedAttempt,
        ]);
        $withCourier = $this->walk($this->shipment(), [
            ShipmentStatus::PickedUp, ShipmentStatus::AtHub, ShipmentStatus::OutForDelivery,
        ]);

        $error = fn (Shipment $s) => (string) $this->lookup($s->number, ['stage' => 'incoming'])->assertStatus(422)->json('error');

        $this->assertStringContainsString('استُلم من المندوب سلفاً', $error($received));
        $this->assertStringContainsString('لم يُقرَّر إرجاعه بعد — حالته «لم يُسلَّم»', $error($undecided));
        $this->assertStringContainsString('ما زال «قيد التوصيل» مع أحمد الساعدي', $error($withCourier));

        $this->lookup('999999', ['stage' => 'incoming'])->assertNotFound()->assertJson(['error' => 'لا وصل برقم 999999.']);
    }

    public function test_handover_refuses_another_merchants_parcel_by_name_and_an_unreceived_one(): void
    {
        $alphas = $this->receive($this->returning($this->alpha));

        $this->assertStringContainsString(
            'راجعُ «متجر M0001» لا هذا التاجر.',
            (string) $this->lookup($alphas->number, ['stage' => 'handover', 'merchant_id' => $this->beta->id])
                ->assertStatus(422)->json('error'),
        );

        $this->lookup($this->qr($alphas), ['stage' => 'handover', 'merchant_id' => $this->alpha->id])
            ->assertOk()
            ->assertJson(['id' => $alphas->id, 'merchant_id' => $this->alpha->id]);

        $still = $this->returning($this->alpha);

        $this->assertStringContainsString(
            'لم يُستلم من المندوب بعد',
            (string) $this->lookup($still->number, ['stage' => 'handover', 'merchant_id' => $this->alpha->id])
                ->assertStatus(422)->json('error'),
        );
    }

    public function test_a_handed_over_return_names_its_receipt(): void
    {
        $shipment = $this->receive($this->returning());
        $batch = Tenancy::runFor($this->company, fn () => app(HandOverReturns::class)
            ->handle([$shipment->id], $this->alpha, $this->staff));

        $this->assertStringContainsString(
            "سُلِّم لتاجره سلفاً بإيصال {$batch->number}",
            (string) $this->lookup($shipment->number, ['stage' => 'handover', 'merchant_id' => $this->alpha->id])
                ->assertStatus(422)->json('error'),
        );
    }

    public function test_the_pickup_courier_takes_only_his_merchants_returns(): void
    {
        $pickup = Tenancy::runFor($this->company, function () {
            $pickup = Courier::create(['code' => 'P1', 'name' => 'حيدر الاستلام', 'phone' => '07720000002',
                'type' => 'pickup', 'status' => 'active']);
            $this->alpha->forceFill(['pickup_courier_id' => $pickup->id])->save();

            return $pickup;
        });

        $alphas = $this->receive($this->returning($this->alpha));
        $betas = $this->receive($this->returning($this->beta));

        $this->lookup($alphas->number, ['stage' => 'pickup', 'courier_id' => $pickup->id])->assertOk();
        $this->assertStringContainsString(
            'تاجره «متجر M0002» ليس من تجّار هذا المندوب.',
            (string) $this->lookup($betas->number, ['stage' => 'pickup', 'courier_id' => $pickup->id])
                ->assertStatus(422)->json('error'),
        );
    }

    public function test_the_returns_screens_carry_the_scan_box_and_a_scan_from_all_opens_the_merchant_ticked(): void
    {
        $shipment = $this->receive($this->returning());

        $this->actingAs($this->staff)->get($this->host().'/returns')
            ->assertOk()->assertSee('data-scan-box', false)->assertSee('returns/lookup?stage=incoming', false);

        // من «الكل»: المسحة تعرف تاجر الطرد، وقائمته تُفتح والطرد معلَّمٌ فيها
        $this->lookup($shipment->number, ['stage' => 'handover'])->assertOk()->assertJson(['merchant_id' => $this->alpha->id]);

        $this->actingAs($this->staff)
            ->get($this->host()."/returns/handover?merchant_id={$this->alpha->id}&scanned={$shipment->id}")
            ->assertOk()
            ->assertSee('data-scanned', false)
            ->assertSee('ممسوح', false);
    }

    public function test_a_merchant_login_cannot_scan_returns(): void
    {
        $merchantUser = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'تاجر', 'phone' => '07790001111', 'password' => 'password',
            'role' => UserRole::Merchant, 'merchant_id' => $this->alpha->id, 'is_active' => true,
        ]));

        $this->actingAs($merchantUser)->getJson($this->host().'/returns/lookup?code=000001')->assertStatus(403);
    }

    // ── التتبّع ─────────────────────────────────────────────────────

    public function test_the_merchant_follows_the_return_until_it_is_in_their_hands(): void
    {
        $pickup = Tenancy::runFor($this->company, fn () => Courier::create(['code' => 'P1', 'name' => 'حيدر الاستلام',
            'phone' => '07720000002', 'type' => 'pickup', 'status' => 'active']));

        $shipment = $this->receive($this->returning());
        $batch = Tenancy::runFor($this->company, fn () => app(HandOverReturns::class)
            ->toPickupCourier([$shipment->id], $pickup, $this->staff)->first());

        $merchantUser = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'تاجر', 'phone' => '07790001111', 'password' => 'password',
            'role' => UserRole::Merchant, 'merchant_id' => $this->alpha->id, 'is_active' => true,
        ]));

        // يؤكّد التاجر من بوابته فيُكتب ذلك في سجلّ الشحنة باسمه
        $this->actingAs($merchantUser)->post($this->host()."/portal/requests/returns/{$batch->id}/confirm")
            ->assertSessionHasNoErrors();

        Tenancy::runFor($this->company, function () use ($shipment, $merchantUser, $batch) {
            $confirmed = ShipmentEvent::where('shipment_id', $shipment->id)->where('event_type', 'return_confirmed')->sole();
            $this->assertSame('merchant', $confirmed->actor_type);
            $this->assertSame($merchantUser->id, (int) $confirmed->actor_id);
            $this->assertStringContainsString($batch->number, (string) $confirmed->note);
        });

        // ومساره عند التاجر: من الرفض إلى يده، بكلامه لا بكلام المخزن
        $this->actingAs($merchantUser)->get($this->host().'/portal/shipments/'.$shipment->id)
            ->assertOk()
            ->assertSeeInOrder(['لم يُسلَّم', 'راجع', 'وصل الراجع مخزننا من المندوب', 'راجع للتاجر', 'تأكّد استلامك للراجع']);
    }

    public function test_the_merchant_sees_a_forced_delivery_without_its_internal_reason(): void
    {
        $shipment = $this->walk($this->shipment(), [ShipmentStatus::PickedUp, ShipmentStatus::AtHub]);

        Tenancy::runFor($this->company, fn () => app(ChangeShipmentStatus::class)->handle(
            $shipment->refresh(), ShipmentStatus::Delivered, $this->staff,
            ['force' => true, 'forced_reason' => 'سبب داخلي للمراجعة'],
        ));

        $merchantUser = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'تاجر', 'phone' => '07790001111', 'password' => 'password',
            'role' => UserRole::Merchant, 'merchant_id' => $this->alpha->id, 'is_active' => true,
        ]));

        $this->actingAs($merchantUser)->get($this->host().'/portal/shipments/'.$shipment->id)
            ->assertOk()
            ->assertSeeInOrder(['بالمخزن', 'واصل'])
            ->assertDontSee('سبب داخلي للمراجعة');
    }

    public function test_the_customer_is_not_told_a_return_on_its_way_is_already_back(): void
    {
        $shipment = $this->returning();

        // الرابط على نطاق الشركة كما يُطبع على وصلها
        $this->get($this->host().parse_url($this->qr($shipment), PHP_URL_PATH))
            ->assertOk()
            ->assertSee('في طريق رجوعها إلى المتجر')
            ->assertDontSee('أُعيدت الشحنة إلى المتجر');
    }
}
