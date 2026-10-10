<?php

namespace Tests\Feature\Portal;

use App\Actions\Returns\ReceiveReturns;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\MerchantRequest;
use App\Models\ReturnBatch;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * طلبات التاجر من بوابته، ودفعات الراجع بإيصالاتها — كما في المعتاد:
 * «طلبات حساب من العملاء»، و«طلبات كشف راجع»، و«تسليم الراجع لمندوب
 * الاستلام»، و«دفعات الراجع».
 */
class MerchantRequestsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $alpha;

    private Merchant $beta;

    private User $staff;

    private User $alphaUser;

    private Courier $courier;

    private Courier $pickup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->alpha = $this->makeMerchant($this->company, 'M0001');
        $this->beta = $this->makeMerchant($this->company, 'M0002');
        $this->staff = $this->makeUser($this->company);

        [$this->courier, $this->pickup, $this->alphaUser] = Tenancy::runFor($this->company, function () {
            $courier = Courier::create(['code' => 'C1', 'name' => 'أحمد', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active']);
            $pickup = Courier::create(['code' => 'P1', 'name' => 'حيدر الاستلام', 'phone' => '07720000002', 'type' => 'pickup', 'status' => 'active']);

            $this->alpha->forceFill(['pickup_courier_id' => $pickup->id])->save();
            $this->beta->forceFill(['pickup_courier_id' => $pickup->id])->save();

            return [$courier, $pickup, User::create([
                'name' => 'تاجر ألفا', 'phone' => '07790000001', 'password' => 'password',
                'role' => UserRole::Merchant, 'merchant_id' => $this->alpha->id, 'is_active' => true,
            ])];
        });
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function walk(Merchant $merchant, array $path): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($merchant, $path) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $merchant->id, 'recipient_name' => 'علي حسين', 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'landmark' => 'قرب الجامع',
                'cod_amount' => 50_000,
            ], $this->staff);

            foreach ($path as $status) {
                app(ChangeShipmentStatus::class)->handle($shipment->refresh(), $status, $this->staff, [
                    'courier_id' => $status === ShipmentStatus::OutForDelivery ? $this->courier->id : null,
                ]);
            }

            return $shipment->refresh();
        });
    }

    /** واصلةٌ حاسبت الشركةُ مندوبَها عليها — فمستحقّها متاحٌ للسحب (docs/plan/49) */
    private function delivered(Merchant $merchant, bool $courierSettled = true): Shipment
    {
        $shipment = $this->walk($merchant, [ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered]);

        if ($courierSettled) {
            Tenancy::runFor($this->company, fn () => $shipment->forceFill(['courier_settled_at' => now()])->save());
        }

        return $shipment;
    }

    /** راجعٌ استُلم من المندوب وعلى رفّ فرع تاجره */
    private function onShelf(Merchant $merchant): Shipment
    {
        $shipment = $this->walk($merchant, [ShipmentStatus::PickedUp, ShipmentStatus::AtHub, ShipmentStatus::OutForDelivery,
            ShipmentStatus::FailedAttempt, ShipmentStatus::Returning]);

        Tenancy::runFor($this->company, fn () => app(ReceiveReturns::class)->handle([$shipment->id], $this->staff));

        return $shipment->refresh();
    }

    private function requests(): \Illuminate\Support\Collection
    {
        return Tenancy::runFor($this->company, fn () => MerchantRequest::orderBy('id')->get());
    }

    // ------------------------------------------------------------ طلب الدفع

    public function test_a_payment_request_is_numbered_and_closes_itself_when_its_statement_is_built(): void
    {
        $this->delivered($this->alpha);

        $this->actingAs($this->alphaUser)
            ->post($this->host().'/portal/requests', [
                'type' => 'payment', 'payout_method' => 'zaincash', 'payout_details' => '07801234567 — علي حسين',
                'via_pickup_courier' => '1', 'note' => 'على المحفظة الجديدة',
            ])
            ->assertSessionHasNoErrors()
            // «تمّ الطلب» برقمه ومبلغه وتاريخه (docs/plan/44)
            ->assertSessionHas('payment_request', fn ($done) => $done['method'] === 'زين كاش' && $done['amount'] > 0);

        $request = $this->requests()->sole();
        $this->assertMatchesRegularExpression('/^REQ-\d{6}-\d+$/', $request->number);
        $this->assertSame('zaincash', $request->payout_method);
        $this->assertSame('07801234567 — علي حسين', $request->payout_details);
        // المحفظة لا يحملها مندوب: «بيد المندوب» للنقد وحده
        $this->assertFalse($request->via_pickup_courier);
        $this->assertTrue($request->isOpen());

        // الشركة تراه ببطاقتيه
        $balance = (int) Tenancy::runFor($this->company, fn () => $this->alpha->refresh()->balance);
        $this->actingAs($this->staff)->get($this->host().'/merchant-requests/payments')
            ->assertOk()
            ->assertSee($request->number)
            ->assertSee('على المحفظة الجديدة')
            ->assertSee('زين كاش')
            ->assertSee('07801234567 — علي حسين')
            ->assertSee('50,000')
            ->assertSee(number_format($balance));

        // بناء كشفه يغلقه ويربطه
        $this->actingAs($this->staff)->post($this->host().'/settlements/merchants', ['merchant_id' => $this->alpha->id]);

        $request = $this->requests()->sole();
        $this->assertSame('handled', $request->status);
        $this->assertNotNull($request->merchant_settlement_id);
        $this->assertSame($this->staff->id, $request->handled_by_user_id);

        $this->actingAs($this->alphaUser)->get($this->host().'/portal/requests')
            ->assertOk()->assertSee('تمّت معالجته')->assertSee($request->settlement->code);
    }

    public function test_one_open_request_per_kind_and_none_for_nothing(): void
    {
        // بلا رصيد ولا راجع
        $this->actingAs($this->alphaUser)->post($this->host().'/portal/requests', ['type' => 'payment'])
            ->assertSessionHasErrors(['type' => 'لا رصيد لك عندنا الآن لتطلب دفعه.']);
        $this->actingAs($this->alphaUser)->post($this->host().'/portal/requests', ['type' => 'returns'])
            ->assertSessionHasErrors(['type' => 'لا راجع لك عندنا الآن.']);

        $this->delivered($this->alpha);
        $this->actingAs($this->alphaUser)->post($this->host().'/portal/requests', ['type' => 'payment'])->assertSessionHasNoErrors();
        $this->actingAs($this->alphaUser)->post($this->host().'/portal/requests', ['type' => 'payment'])
            ->assertSessionHasErrors('type');

        $this->assertCount(1, $this->requests());

        // يُلغيه ما دام مفتوحاً، فيطلب من جديد
        $first = $this->requests()->first();
        $this->actingAs($this->alphaUser)->post($this->host()."/portal/requests/{$first->id}/cancel")->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $this->requests()->first()->status);
        $this->actingAs($this->alphaUser)->post($this->host().'/portal/requests', ['type' => 'payment'])->assertSessionHasNoErrors();
        $this->assertCount(2, $this->requests());
    }

    public function test_a_merchant_cannot_touch_another_merchants_request_or_receipt(): void
    {
        $this->delivered($this->beta);
        $theirs = Tenancy::runFor($this->company, fn () => MerchantRequest::create([
            'merchant_id' => $this->beta->id, 'type' => 'payment', 'number' => 'REQ-X', 'status' => 'open',
        ]));

        $this->actingAs($this->alphaUser)->post($this->host()."/portal/requests/{$theirs->id}/cancel")->assertNotFound();
        $this->assertSame('open', $this->requests()->first()->status);

        $shelf = $this->onShelf($this->beta);
        $this->actingAs($this->staff)->post($this->host().'/returns/handover', ['merchant_id' => $this->beta->id, 'shipment_ids' => [$shelf->id]]);
        $batch = Tenancy::runFor($this->company, fn () => ReturnBatch::sole());

        $this->actingAs($this->alphaUser)->get($this->host()."/portal/requests/returns/{$batch->id}/print")->assertNotFound();
        $this->actingAs($this->alphaUser)->post($this->host()."/portal/requests/returns/{$batch->id}/confirm")->assertNotFound();
    }

    public function test_closing_by_hand_needs_the_right_ability(): void
    {
        $this->delivered($this->alpha);
        $this->actingAs($this->alphaUser)->post($this->host().'/portal/requests', ['type' => 'payment']);
        $request = $this->requests()->sole();

        $returnsClerk = $this->makeUser($this->company, UserRole::CustomerService);
        $this->actingAs($returnsClerk)->post($this->host()."/merchant-requests/payments/{$request->id}/handle")->assertForbidden();

        // ولا يُغلق طلب مالٍ من مسار الراجع
        $this->actingAs($this->staff)->post($this->host()."/returns/requests/{$request->id}/handle")->assertNotFound();

        $this->actingAs($this->staff)->post($this->host()."/merchant-requests/payments/{$request->id}/handle")->assertSessionHasNoErrors();
        $this->assertSame('handled', $this->requests()->sole()->status);
    }

    // ----------------------------------------------------- الراجع ودفعاته

    public function test_handing_returns_at_the_store_makes_a_received_receipt_and_answers_the_request(): void
    {
        $shelf = $this->onShelf($this->alpha);

        $this->actingAs($this->alphaUser)->post($this->host().'/portal/requests', ['type' => 'returns', 'via_pickup_courier' => '0'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->staff)->get($this->host().'/returns/requests')
            ->assertOk()->assertSee($this->requests()->sole()->number)->assertSee('سلّمها له');

        $this->actingAs($this->staff)
            ->post($this->host().'/returns/handover', ['merchant_id' => $this->alpha->id, 'shipment_ids' => [$shelf->id]])
            ->assertSessionHas('print');

        $batch = Tenancy::runFor($this->company, fn () => ReturnBatch::sole());
        $this->assertSame('store', $batch->via);
        $this->assertSame(1, $batch->shipments_count);
        $this->assertNotNull($batch->received_at);
        $this->assertSame($batch->id, $shelf->fresh()->return_batch_id);
        $this->assertSame(ShipmentStatus::Returned, $shelf->fresh()->status);

        $request = $this->requests()->sole();
        $this->assertSame('handled', $request->status);
        $this->assertSame($batch->id, $request->return_batch_id);

        $this->actingAs($this->staff)->get($this->host()."/return-batches/{$batch->id}/print")
            ->assertOk()->assertSee($batch->number)->assertSee($shelf->number)->assertSee('توقيع التاجر');
    }

    /** إيصالات الراجع: البحث باسم التاجر، ثم كل رواجعه التي استلمها في المدّة شحنةً شحنة */
    public function test_return_receipts_find_a_merchant_by_name_and_list_all_his_returns_in_a_period(): void
    {
        Tenancy::runFor($this->company, function () {
            $this->alpha->forceFill(['business_name' => 'متجر النخيل'])->save();
            $this->beta->forceFill(['business_name' => 'بيت العطور'])->save();
        });

        $first = $this->onShelf($this->alpha);
        $second = $this->onShelf($this->alpha);
        $theirs = $this->onShelf($this->beta);

        $this->actingAs($this->staff)->post($this->host().'/returns/handover', ['merchant_id' => $this->alpha->id, 'shipment_ids' => [$first->id]])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->post($this->host().'/returns/handover', ['merchant_id' => $this->alpha->id, 'shipment_ids' => [$second->id]])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->post($this->host().'/returns/handover', ['merchant_id' => $this->beta->id, 'shipment_ids' => [$theirs->id]])
            ->assertSessionHasNoErrors();
        $this->assertSame(ShipmentStatus::Returned, $second->fresh()->status);

        // البحث بالاسم يختار التاجر: إيصالاه، وكل رواجعه — لا رواجع غيره (ورسالة التسليم الأخيرة تُقرأ أوّلاً)
        $this->actingAs($this->staff)->get($this->host().'/return-batches')->assertOk();
        $page = $this->actingAs($this->staff)->get($this->host().'/return-batches?q='.urlencode('النخيل'))->assertOk();
        $page->assertSee('كل رواجع متجر النخيل')->assertSee($first->number)->assertSee($second->number)
            ->assertDontSee($theirs->number);

        // ومدّةٌ لم يستلم فيها شيئاً
        $this->actingAs($this->staff)->get($this->host().'/return-batches?merchant_id='.$this->alpha->id.'&from=2000-01-01&to=2000-01-31')
            ->assertOk()->assertSee('لا رواجع استلمها في هذه المدّة.');

        // وكشفها مطبوعاً بتوقيعين
        $this->actingAs($this->staff)->get($this->host().'/return-batches/merchant?merchant_id='.$this->alpha->id.'&from='.today()->toDateString())
            ->assertOk()->assertSee('كشف رواجع تاجر')->assertSee($first->number)->assertSee($second->number)
            ->assertDontSee($theirs->number)->assertSee('توقيع التاجر');
    }

    public function test_a_returns_request_shows_its_details_without_a_stray_separator(): void
    {
        $this->onShelf($this->alpha);

        $this->actingAs($this->alphaUser)->post($this->host().'/portal/requests', ['type' => 'returns', 'via_pickup_courier' => '1'])
            ->assertSessionHasNoErrors();

        // طلب الراجع لا طريقة دفعٍ له: «مع مندوب الاستلام» وحدها، لا «· مع مندوب الاستلام»
        $page = $this->actingAs($this->alphaUser)->get($this->host().'/portal/requests')->assertOk()->assertSee('مع مندوب الاستلام');
        $this->assertDoesNotMatchRegularExpression('/<td[^>]*>\s*·/u', $page->getContent());
    }

    public function test_the_pickup_courier_carries_returns_with_a_receipt_per_merchant_until_confirmed(): void
    {
        $a = $this->onShelf($this->alpha);
        $b = $this->onShelf($this->beta);

        $this->actingAs($this->staff)->get($this->host().'/returns/pickup-courier?courier_id='.$this->pickup->id)
            ->assertOk()->assertSee($a->number)->assertSee($b->number);

        $this->actingAs($this->staff)
            ->post($this->host().'/returns/pickup-courier', ['courier_id' => $this->pickup->id, 'shipment_ids' => [$a->id, $b->id]])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'بإيصالاتٍ عددها 2'));

        $batches = Tenancy::runFor($this->company, fn () => ReturnBatch::orderBy('id')->get());
        $this->assertCount(2, $batches);
        $this->assertSame(['pickup_courier', 'pickup_courier'], $batches->pluck('via')->all());
        $this->assertTrue($batches->every(fn ($batch) => $batch->courier_id === $this->pickup->id && $batch->received_at === null));

        // إيصالات التسليم معاً، لكلٍّ صفحته
        $this->actingAs($this->staff)->get($this->host().'/return-batches/print?'.http_build_query(['ids' => $batches->pluck('id')->all()]))
            ->assertOk()->assertSee($batches[0]->number)->assertSee($batches[1]->number)->assertSee('حمله: حيدر الاستلام');

        $this->actingAs($this->staff)->get($this->host().'/return-batches?received=no')
            ->assertOk()->assertSee($batches[0]->number)->assertSee('لم يؤكَّد');

        // التاجر يؤكّد من بوابته ما وصله
        $mine = $batches->firstWhere('merchant_id', $this->alpha->id);
        $this->actingAs($this->alphaUser)->get($this->host().'/portal/requests')->assertSee($mine->number)->assertSee('وصلتني');
        $this->actingAs($this->alphaUser)->get($this->host()."/portal/requests/returns/{$mine->id}/print")
            ->assertOk()->assertSee($mine->number)->assertSee($a->number)->assertDontSeeNumber($b->number);
        $this->actingAs($this->alphaUser)->post($this->host()."/portal/requests/returns/{$mine->id}/confirm")->assertSessionHasNoErrors();
        $this->actingAs($this->alphaUser)->post($this->host()."/portal/requests/returns/{$mine->id}/confirm")->assertSessionHasErrors('batch');

        Tenancy::runFor($this->company, function () use ($mine) {
            $this->assertNotNull($mine->refresh()->received_at);
            $this->assertSame('التاجر من بوابته', $mine->received_by);
        });

        // والآخر بيد موظّف
        $theirs = $batches->firstWhere('merchant_id', $this->beta->id);
        $this->actingAs($this->staff)->post($this->host()."/return-batches/{$theirs->id}/confirm")->assertSessionHasNoErrors();
        Tenancy::runFor($this->company, fn () => $this->assertSame($this->staff->name, $theirs->refresh()->received_by));
    }

    public function test_only_active_pickup_couriers_carry_returns(): void
    {
        $shelf = $this->onShelf($this->alpha);

        $this->actingAs($this->staff)
            ->post($this->host().'/returns/pickup-courier', ['courier_id' => $this->courier->id, 'shipment_ids' => [$shelf->id]])
            ->assertSessionHasErrors('courier_id');

        $this->assertSame(ShipmentStatus::Returning, $shelf->fresh()->status);
    }

    /** النقد بيد المندوب أو من الشركة، والبطاقة بلا رقمٍ تُرَدّ، وما أخفته الشركة لا يُطلب */
    public function test_payment_methods_come_with_their_details(): void
    {
        $this->delivered($this->alpha);

        $this->actingAs($this->alphaUser)->post($this->host().'/portal/requests', ['type' => 'payment', 'payout_method' => 'qi'])
            ->assertSessionHasErrors(['payout_details' => 'اكتب رقم البطاقة أو المحفظة واسم صاحبها.']);

        // الشركة لا تتعامل بـ Qi: يختفي من البوابة ولا يُقبل
        $this->actingAs($this->staff)->put($this->host().'/settings/company', [
            'primary_color' => '#0D9488', 'payout_form' => 1, 'payout_offered' => ['cash', 'zaincash'],
        ]);
        $this->actingAs($this->alphaUser)->get($this->host().'/portal/requests')->assertOk()
            ->assertSee('value="cash"', false)->assertDontSee('value="qi"', false);
        $this->actingAs($this->alphaUser)->post($this->host().'/portal/requests', ['type' => 'payment', 'payout_method' => 'qi', 'payout_details' => '123'])
            ->assertSessionHasErrors('payout_method');

        $this->actingAs($this->alphaUser)->post($this->host().'/portal/requests', ['type' => 'payment', 'payout_method' => 'cash', 'via_pickup_courier' => '0'])
            ->assertSessionHasNoErrors();
        $request = $this->requests()->sole();
        $this->assertNull($request->payout_details);
        $this->assertFalse($request->via_pickup_courier);
        $this->actingAs($this->staff)->get($this->host().'/merchant-requests/payments')->assertSee('يستلمه من الشركة');
    }

    /** يُطلب المتاحُ للسحب وحده: ما نقده مع المندوب قيد المطابقة (docs/plan/49) */
    public function test_a_payment_request_waits_for_the_courier_to_be_settled(): void
    {
        $this->delivered($this->alpha, courierSettled: false);

        $this->actingAs($this->alphaUser)->get($this->host().'/portal')
            ->assertOk()->assertSee('إجمالي المستحقات')->assertSee('المتاح للسحب')->assertSee('قيد المطابقة');

        $this->actingAs($this->alphaUser)->post($this->host().'/portal/requests', ['type' => 'payment', 'payout_method' => 'cash'])
            ->assertSessionHasErrors();
        $this->assertSame(0, $this->requests()->count());
    }
}
