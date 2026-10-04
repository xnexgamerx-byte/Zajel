<?php

namespace Tests\Feature\Settlements;

use App\Actions\Returns\ReceiveReturns;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Actions\Shipments\UpdateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\City;
use App\Models\CitySetting;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\PriceList;
use App\Models\PriceListRule;
use App\Models\Shipment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ledger;
use App\Services\Shipments\ShipmentStages;
use App\Support\Tenancy\Tenancy;
use App\Support\Tracking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * طلب الاستبدال — يُسلَّم الجديد ويُستلَم القديم (docs/plan/31):
 * «الاستبدال يُحسب توصيلاً عاديّاً، حاله حال التوصيل الاعتيادي، ما كو فرق» —
 * و«نحسبه شحنة، والمستبدل راجع جزئي». أجرته أجرة التوصيل، وتسليمه «واصل جزئي»
 * (docs/plan/24): أجرة التوصيل وعمولة المندوب كاملتين، والقديم يرجع لتاجره بلا أجرة
 * راجعٍ ولا عمولة إرجاع.
 */
class ExchangeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $owner;

    private Courier $courier;

    private User $courierUser;

    private City $taji;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);

        $this->taji = City::firstOrCreate(['governorate_id' => $this->baghdad()->id, 'name_ar' => 'التاجي'],
            ['name_en' => 'Taji', 'is_active' => true]);

        // بغداد: ٥٠٠٠ للمركز و٧٠٠٠ للأطراف. وأجرة «استبدال» قديمة في القاعدة لا يُلتفت إليها
        Tenancy::runFor($this->company, function () {
            PriceListRule::create([
                'price_list_id' => PriceList::where('is_default', true)->value('id'),
                'to_governorate_id' => $this->baghdad()->id, 'weight_from_grams' => 0, 'weight_to_grams' => 5000,
                'delivery_fee' => 5000, 'peripheral_fee' => 7000, 'return_fee' => 2500, 'replacement_fee' => 6500,
                'priority' => 1, 'is_active' => true,
            ]);
            CitySetting::create(['city_id' => $this->taji->id, 'is_peripheral' => true]);

            $this->courier = Courier::create([
                'code' => 'C1', 'name' => 'أحمد', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active',
                'commission_per_delivery' => 2000, 'commission_per_return' => 1000,
            ]);
            $this->courierUser = User::create([
                'name' => 'أحمد', 'phone' => '07720000001', 'password' => 'password',
                'role' => UserRole::Courier, 'courier_id' => $this->courier->id, 'is_active' => true,
            ]);
        });
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function create(array $data = []): Shipment
    {
        return Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle($data + [
            'merchant_id' => $this->merchant->id, 'recipient_name' => 'زبون', 'recipient_phone' => '07801234567',
            'governorate_id' => $this->baghdad()->id, 'address' => 'العنوان', 'cod_amount' => 50_000,
        ], $this->owner));
    }

    private function move(Shipment $shipment, ShipmentStatus $to, array $options = []): Shipment
    {
        return Tenancy::runFor($this->company, fn () => app(ChangeShipmentStatus::class)->handle(
            $shipment->refresh(), $to, $this->owner,
            $options + ['courier_id' => $to === ShipmentStatus::OutForDelivery ? $this->courier->id : null]));
    }

    /** طلب استبدالٍ بـ٥٠٬٠٠٠ خرج مع المندوب */
    private function exchangeWithCourier(): Shipment
    {
        $shipment = $this->create(['type' => 'exchange']);
        foreach ([ShipmentStatus::PickedUp, ShipmentStatus::AtHub, ShipmentStatus::OutForDelivery] as $status) {
            $this->move($shipment, $status);
        }

        return $shipment->refresh();
    }

    private function fresh(Shipment $shipment): Shipment
    {
        return Tenancy::runFor($this->company, fn () => $shipment->refresh());
    }

    private function postings(Shipment $shipment, string $account, string $category): array
    {
        return Tenancy::runFor($this->company, fn () => Transaction::where('shipment_id', $shipment->id)
            ->where('account_type', $account)->where('category', $category)->pluck('amount')->map(fn ($a) => (int) $a)->all());
    }

    private function assertBooksBalance(): void
    {
        Tenancy::runFor($this->company, function () {
            $ledger = app(Ledger::class);
            $this->assertCount(0, $ledger->shipmentsOffLedger(), 'شحنةٌ قيودها لا تساوي مستحقّها');
            $off = $ledger->balancesOff();
            $this->assertCount(0, $off['merchants']);
            $this->assertCount(0, $off['couriers']);
        });
    }

    // ── الأجرة: أجرة التوصيل نفسها ─────────────────────────────────

    public function test_an_exchange_costs_exactly_what_a_new_order_costs(): void
    {
        $new = $this->create();
        $exchange = $this->create(['type' => 'exchange']);

        $this->assertSame('exchange', $exchange->type);
        $this->assertSame(5000, (int) $exchange->delivery_fee);
        $this->assertSame(
            [(int) $new->delivery_fee, (int) $new->return_fee, (int) $new->total_fees, (int) $new->merchant_due],
            [(int) $exchange->delivery_fee, (int) $exchange->return_fee, (int) $exchange->total_fees, (int) $exchange->merchant_due],
        );

        // والأطراف بأجرة الأطراف، استبدالاً كان أو جديداً
        $this->assertSame(7000, (int) $this->create(['type' => 'exchange', 'city_id' => $this->taji->id])->delivery_fee);
    }

    public function test_changing_the_type_does_not_reprice(): void
    {
        $shipment = $this->create();
        $edit = fn (array $changes) => Tenancy::runFor($this->company, fn () => app(UpdateShipment::class)->handle(
            $shipment->refresh(),
            $changes + ['delivery_fee' => null] + UpdateShipment::withAmount($shipment->refresh(), 50_000),
            $this->owner,
        ));

        $edited = $edit(['type' => 'exchange']);
        $this->assertSame('exchange', $edited->type);
        $this->assertSame(5000, (int) $edited->delivery_fee);
        $this->assertSame(45_000, (int) $edited->merchant_due);
    }

    public function test_the_merchant_portal_records_the_exchange_at_the_normal_fee(): void
    {
        $merchantUser = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'تاجر', 'phone' => '07790000001', 'password' => 'password',
            'role' => UserRole::Merchant, 'merchant_id' => $this->merchant->id, 'is_active' => true,
        ]));

        $this->actingAs($merchantUser)->post($this->host().'/portal/shipments', [
            'recipient_name' => 'طه محمد', 'recipient_phone' => '07712345678',
            'governorate_id' => $this->baghdad()->id, 'city_id' => $this->area('المنصور'),
            'pieces_count' => 1, 'cod_amount' => 25_000, 'type' => 'exchange',
        ])->assertSessionHasNoErrors();

        $shipment = Tenancy::runFor($this->company, fn () => Shipment::latest('id')->firstOrFail());
        $this->assertSame('exchange', $shipment->type);
        $this->assertSame(5000, (int) $shipment->delivery_fee);
    }

    public function test_the_price_list_has_no_exchange_column(): void
    {
        $listId = Tenancy::runFor($this->company, fn () => PriceList::where('is_default', true)->value('id'));

        $this->actingAs($this->owner)->get($this->host().'/pricing/'.$listId)
            ->assertOk()
            ->assertDontSee('replacement_fee')
            ->assertDontSee('>الاستبدال<', false)
            ->assertSee('وطلب الاستبدال بأجرة التوصيل نفسها.');
    }

    // ── التسليم: شحنةٌ واصلة، والقديم راجعٌ جزئي ──────────────────────

    public function test_a_delivered_exchange_is_a_delivery_and_its_old_item_returns_free(): void
    {
        $done = $this->move($this->exchangeWithCourier(), ShipmentStatus::Delivered);

        // واصلٌ: أجرة التوصيل كاملةً من المحصَّل، وعمولة التوصيل كاملةً للمندوب، ولا أجرة راجع
        $this->assertSame(ShipmentStatus::PartiallyDelivered, $done->status);
        $this->assertNotNull($done->delivered_at);
        $this->assertSame(
            ['collected' => 50_000, 'fee' => 5000, 'return_fee' => 0, 'merchant_due' => 45_000, 'commission' => 2000],
            ['collected' => (int) $done->collected_amount, 'fee' => (int) $done->delivery_fee, 'return_fee' => (int) $done->return_fee,
                'merchant_due' => (int) $done->merchant_due, 'commission' => (int) $done->courier_commission],
        );
        $this->assertStringStartsWith('استبدال: سُلِّم الجديد',
            Tenancy::runFor($this->company, fn () => $done->events()->where('to_status', 'partially_delivered')->value('note')));

        // نقرةٌ ثانية على «واصل» لا تقيّد شيئاً ثانيةً
        $this->move($done, ShipmentStatus::Delivered);
        $this->assertSame([2000], $this->postings($done, 'courier', 'commission'));
        $this->assertBooksBalance();

        // سُلِّم بمبلغه كما هو: لا ينتظر «موافقة التسليم»
        $this->assertFalse(Tenancy::runFor($this->company, fn () => ShipmentStages::awaitingApproval(Shipment::query())
            ->whereKey($done->id)->exists()));

        // القديم: يُستلم من المندوب مباشرةً — بلا خطوة «راجع» يدوية — ثم يُسلَّم لتاجره بلا أجرة
        Tenancy::runFor($this->company, fn () => app(ReceiveReturns::class)->handle([$done->id], $this->owner));
        $onShelf = $this->fresh($done);
        $this->assertSame(ShipmentStatus::Returning, $onShelf->status);
        $this->assertNotNull($onShelf->return_received_at);
        $this->assertBooksBalance();

        $returned = $this->move($onShelf, ShipmentStatus::Returned);

        $this->assertSame([45_000, 2000], [(int) $returned->merchant_due, (int) $returned->courier_commission]);
        $this->assertSame([], $this->postings($returned, 'merchant', 'return_fee'));
        $this->assertSame([2000], $this->postings($returned, 'courier', 'commission'));
        $this->assertSame(45_000, (int) $this->merchant->fresh()->balance);
        $this->assertSame(2000, (int) $this->courier->fresh()->commission_balance);
        $this->assertBooksBalance();
    }

    public function test_the_courier_hands_over_the_new_item_and_the_old_one_reaches_the_returns_screen(): void
    {
        $shipment = $this->exchangeWithCourier();

        $this->actingAs($this->courierUser)->get($this->host().'/courier/shipments/'.$shipment->id)
            ->assertOk()
            ->assertSee('سلّمت الجديد واستلمت القديم')
            ->assertSee('القطعة القديمة');

        $this->actingAs($this->courierUser)->post($this->host().'/courier/shipments/'.$shipment->id, ['action' => 'delivered'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', "سُجِّل استبدال الشحنة {$shipment->number}. سلّم القطعة القديمة للمخزن مع الراجع.");
        $this->assertSame(ShipmentStatus::PartiallyDelivered, $this->fresh($shipment)->status);

        // «سلّمت اليوم» يعدّه
        $this->actingAs($this->courierUser)->get($this->host().'/courier/today')
            ->assertOk()->assertSeeInOrder(['سلّمت اليوم', '1']);

        // في «استلام الراجع من المندوب» بسببه وبلا أجرة راجع، ومسحه يُعلَّم هناك
        $this->actingAs($this->owner)->get($this->host().'/returns')
            ->assertOk()->assertSee($shipment->number)->assertSee('القطعة القديمة من استبدال');

        $lookup = fn (string $stage) => $this->actingAs($this->owner)->getJson($this->host().'/returns/lookup?'
            .http_build_query(['code' => $shipment->number, 'stage' => $stage]));
        $lookup('incoming')->assertOk()->assertJson(['id' => $shipment->id]);
        $lookup('handover')->assertStatus(422)
            ->assertJson(['error' => "{$shipment->number}: قديم استبدالٍ لم يُستلم من المندوب بعد — يُستلم أوّلاً من «استلام الراجع من المندوب»."]);

        $this->actingAs($this->owner)->post($this->host().'/returns/receive', ['shipment_ids' => [$shipment->id]])
            ->assertSessionHas('success', 'استُلم الراجع من المندوب، عدد الشحنات 1.');

        $received = $this->fresh($shipment);
        $this->assertSame(ShipmentStatus::Returning, $received->status);
        $this->assertNotNull($received->return_received_at);
        $this->assertSame(0, (int) $received->return_fee);

        // والزبون لا يُقال له «لم تُسلَّم» وقد استلم طلبه — ولو مضى القديم راجعاً
        $this->get($this->host().'/t/'.$shipment->number.'/'.Tracking::token($received))
            ->assertOk()->assertSee('سُلِّم طلب الاستبدال. شكراً لك.')->assertDontSee('لم تُسلَّم الشحنة');

        // ولوحة المراحل: خرج من «راجع عند المندوب» إلى «راجع بالمخزن»
        $stage = fn (string $key) => Tenancy::runFor($this->company,
            fn () => ShipmentStages::apply(Shipment::query(), $key)->whereKey($shipment->id)->exists());
        $this->assertFalse($stage('return_with_courier'));
        $this->assertTrue($stage('return_on_shelf'));
        $this->assertTrue($stage('partial_or_exchange'));
    }

    /** الاستبدال الذي رفضه الزبون لم يُسلَّم منه شيء: راجعٌ عاديّ بأجرته وعمولة إرجاعه */
    public function test_a_refused_exchange_returns_like_any_other(): void
    {
        $shipment = $this->exchangeWithCourier();
        $this->move($shipment, ShipmentStatus::FailedAttempt);
        $this->move($shipment, ShipmentStatus::Returning);
        Tenancy::runFor($this->company, fn () => app(ReceiveReturns::class)->handle([$shipment->id], $this->owner));

        $returned = $this->move($shipment, ShipmentStatus::Returned);

        $this->assertNull($returned->delivered_at);
        $this->assertSame([-2500, 1000], [(int) $returned->merchant_due, (int) $returned->courier_commission]);
        $this->assertSame([2500], $this->postings($returned, 'merchant', 'return_fee'));
        $this->assertBooksBalance();
    }

    /** والموظّف من صفحة الشحنة: «واصل» للاستبدال يُسجَّل «واصل جزئي» كما من المندوب */
    public function test_staff_marking_an_exchange_delivered_records_the_partial_return(): void
    {
        $shipment = $this->exchangeWithCourier();

        $this->actingAs($this->owner)->get($this->host().'/shipments/'.$shipment->id)
            ->assertOk()->assertSee('طلب استبدال: يُسجَّل «واصل جزئي»');

        $this->actingAs($this->owner)->post($this->host().'/shipments/'.$shipment->id.'/status', ['status' => 'delivered'])
            ->assertSessionHasNoErrors();

        $done = $this->fresh($shipment);
        $this->assertSame(ShipmentStatus::PartiallyDelivered, $done->status);
        $this->assertSame([50_000, 0, 45_000], [(int) $done->collected_amount, (int) $done->return_fee, (int) $done->merchant_due]);
    }
}
