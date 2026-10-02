<?php

namespace Tests\Feature\Shipments;

use App\Actions\Returns\ReceiveReturns;
use App\Actions\Settlements\BuildCourierSettlement;
use App\Actions\Settlements\BuildMerchantSettlement;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\ConfirmAmount;
use App\Actions\Shipments\CreateShipment;
use App\Actions\Shipments\UpdateShipment;
use App\Enums\ShipmentStatus;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ledger;
use App\Services\Shipments\ShipmentStages;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * الواصل الجزئي (الوثيقة ٢٤): ما سُلِّم تُؤخذ عنه أجرة التوصيل كاملةً وتُقيَّد عمولة المندوب،
 * وباقيه يرجع لتاجره راجعاً عاديّاً بلا أجرة راجعٍ ولا عمولة إرجاع — والحساب، والدفتر،
 * والتسويات، والتقارير تراه شحنةً سُلِّمت ولو صارت حالتها «راجع».
 */
class PartialDeliveryTest extends TestCase
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
        $this->merchant = $this->makeMerchant($this->company);   // توصيل ٥٠٠٠، راجع ٢٥٠٠
        $this->owner = $this->makeUser($this->company);

        $this->courier = Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => 'C1', 'name' => 'أحمد', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active',
            'commission_per_delivery' => 2000, 'commission_per_return' => 1000,
        ]));
    }

    private function shipment(int $cod = 50_000): Shipment
    {
        return Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle([
            'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي', 'recipient_phone' => '07801234567',
            'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'cod_amount' => $cod,
        ], $this->owner));
    }

    private function move(Shipment $shipment, ShipmentStatus $to, array $options = []): Shipment
    {
        return Tenancy::runFor($this->company, fn () => app(ChangeShipmentStatus::class)->handle(
            $shipment->refresh(), $to, $this->owner,
            $options + ['courier_id' => $to === ShipmentStatus::OutForDelivery ? $this->courier->id : null]));
    }

    /** خرجت مع المندوب فسُلِّم بعضها بـ٣٠٬٠٠٠ من ٥٠٬٠٠٠ */
    private function partial(): Shipment
    {
        $shipment = $this->shipment();
        foreach ([ShipmentStatus::PickedUp, ShipmentStatus::AtHub, ShipmentStatus::OutForDelivery] as $status) {
            $this->move($shipment, $status);
        }

        return $this->move($shipment, ShipmentStatus::PartiallyDelivered, ['collected_amount' => 30_000]);
    }

    /** باقيه يرجع: قيد الإرجاع، فيُستلم من المندوب، فيُسلَّم لتاجره */
    private function returnRest(Shipment $shipment, bool $toMerchant = true): Shipment
    {
        $this->move($shipment, ShipmentStatus::Returning);
        Tenancy::runFor($this->company, fn () => app(ReceiveReturns::class)->handle([$shipment->id], $this->owner));

        return $toMerchant ? $this->move($shipment, ShipmentStatus::Returned) : $shipment->refresh();
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

    public function test_the_delivered_part_pays_the_full_delivery_fee_and_the_rest_returns_free(): void
    {
        $shipment = $this->partial();

        // التسليم: أجرة التوصيل كاملةً من المحصَّل، وعمولة التوصيل كاملةً للمندوب
        $this->assertNotNull($shipment->delivered_at);
        $this->assertSame(0, (int) $shipment->return_fee);
        $this->assertSame(30_000 - 5000, (int) $shipment->merchant_due);
        $this->assertSame(2000, (int) $shipment->courier_commission);
        $this->assertBooksBalance();

        // في الطريق إلى تاجره: لا يتحرّك مال، والدفتر مطابقٌ في كل خطوة
        $onTheWay = $this->returnRest($shipment, toMerchant: false);
        $this->assertSame(ShipmentStatus::Returning, $onTheWay->status);
        $this->assertBooksBalance();

        $returned = $this->move($shipment, ShipmentStatus::Returned);

        $this->assertSame(ShipmentStatus::Returned, $returned->status);
        $this->assertSame(25_000, (int) $returned->merchant_due);
        $this->assertSame(2000, (int) $returned->courier_commission);
        $this->assertSame([], $this->postings($returned, 'merchant', 'return_fee'));
        $this->assertSame([2000], $this->postings($returned, 'courier', 'commission'));
        $this->assertSame(25_000, (int) $this->merchant->fresh()->balance);
        $this->assertSame(2000, (int) $this->courier->fresh()->commission_balance);
        $this->assertBooksBalance();
    }

    /** والراجع حقّاً — لم يُسلَّم منه شيء — على حاله: أجرة الراجع على التاجر وعمولة الإرجاع للمندوب */
    public function test_a_plain_return_still_pays_its_return_fee_and_commission(): void
    {
        $shipment = $this->shipment();
        foreach ([ShipmentStatus::PickedUp, ShipmentStatus::AtHub, ShipmentStatus::OutForDelivery, ShipmentStatus::FailedAttempt] as $status) {
            $this->move($shipment, $status);
        }

        $returned = $this->returnRest($shipment);

        $this->assertNull($returned->delivered_at);
        $this->assertSame(-2500, (int) $returned->merchant_due);
        $this->assertSame(1000, (int) $returned->courier_commission);
        $this->assertSame([2500], $this->postings($returned, 'merchant', 'return_fee'));
        $this->assertSame([1000], $this->postings($returned, 'courier', 'commission'));
        $this->assertBooksBalance();
    }

    public function test_the_rest_does_not_go_out_for_delivery_again(): void
    {
        $shipment = $this->partial();
        $this->move($shipment, ShipmentStatus::AtHub);

        foreach ([[ShipmentStatus::OutForDelivery, []], [ShipmentStatus::Delivered, ['force' => true, 'forced_reason' => 'سلّمه']]] as [$to, $options]) {
            try {
                $this->move($shipment, $to, $options);
                $this->fail("خرج باقي الواصل الجزئي إلى «{$to->label()}»");
            } catch (ValidationException $e) {
                $this->assertStringContainsString('باقيها راجعٌ لتاجرها', collect($e->errors())->flatten()->first());
            }
        }

        // وإسناده مع شحنات اليوم (المسح، والإسناد الجماعي) يتخطّاه بسببه
        $result = Tenancy::runFor($this->company, fn () => app(\App\Actions\Shipments\SendOutForDelivery::class)
            ->handle(Shipment::whereKey($shipment->id)->get(), $this->courier, $this->owner));
        $this->assertSame([], $result['moved']);
        $this->assertSame(['واصل جزئي — باقيه راجعٌ لتاجره'], array_values($result['skipped']));

        $this->assertSame([25_000], $this->postings($shipment, 'merchant', 'shipment_due'));
        $this->assertBooksBalance();
    }

    public function test_settlements_take_the_delivered_part_while_the_rest_is_on_its_way_back(): void
    {
        $shipment = $this->returnRest($this->partial(), toMerchant: false);

        [$courierSheet, $merchantSheet] = Tenancy::runFor($this->company, fn () => [
            app(BuildCourierSettlement::class)->handle($this->courier, $this->owner),
            app(BuildMerchantSettlement::class)->handle($this->merchant, $this->owner),
        ]);

        $this->assertSame(30_000, (int) $courierSheet->cod_total);
        $this->assertSame(2000, (int) $courierSheet->commission_total);

        $line = Tenancy::runFor($this->company, fn () => $merchantSheet->lines()->sole());
        $this->assertSame(ShipmentStatus::PartiallyDelivered->value, $line->shipment_status);
        $this->assertSame(30_000, (int) $line->collected_amount);
        $this->assertSame(5000, (int) $line->delivery_fee);
        $this->assertSame(0, (int) $line->return_fee);
        $this->assertSame(25_000, (int) $line->net_amount);
        $this->assertSame(0, (int) $merchantSheet->returned_count);
        $this->assertSame(25_000, (int) $merchantSheet->net_amount);

        // ورجوع الباقي بعد بناء الكشف لا يحرّك مالاً: سطره يبقى مطابقاً لمستحقّ الشحنة
        $returned = $this->move($shipment, ShipmentStatus::Returned);
        $this->assertSame((int) $line->net_amount, (int) $returned->merchant_due);
        $this->assertBooksBalance();
    }

    public function test_the_rest_is_not_edited_and_its_amount_can_still_be_approved(): void
    {
        $shipment = $this->returnRest($this->partial(), toMerchant: false);

        $this->assertFalse(UpdateShipment::editable($shipment));
        $this->assertSame([$shipment->id], Tenancy::runFor($this->company,
            fn () => ShipmentStages::awaitingApproval(Shipment::query())->pluck('id')->all()));

        $confirmed = Tenancy::runFor($this->company, fn () => app(ConfirmAmount::class)->handle($shipment, 32_000, $this->owner));

        $this->assertTrue($confirmed->amount_confirmed);
        $this->assertSame(27_000, (int) $confirmed->merchant_due);
        $this->assertBooksBalance();
    }

    /** أجرةٌ خُصمت من المحصَّل عند التسليم لا تُقبض مقدّماً بعده، ولو مضى الباقي راجعاً */
    public function test_fees_taken_at_the_partial_delivery_are_not_collected_again(): void
    {
        Tenancy::runFor($this->company, fn () => $this->merchant->update(['prepaid_billing' => true]));

        $shipment = $this->returnRest($this->partial(), toMerchant: false);

        $this->assertTrue($shipment->fee_prepaid);
        $this->assertNull($shipment->prepaid_receipt_id);
        $this->assertSame(0, Tenancy::runFor($this->company,
            fn () => \App\Actions\Cash\ReceivePrepaidFees::pending(Shipment::query())->count()));
    }

    /** في التقارير: الواصل الجزئي مسلَّمٌ بأجوره كاملةً ولو رجع باقيه — والراجع حقّاً أجرة رجوعه */
    public function test_reports_count_the_partial_delivery_as_delivered_with_its_full_fees(): void
    {
        $partial = $this->returnRest($this->partial());

        $plain = $this->shipment();
        foreach ([ShipmentStatus::PickedUp, ShipmentStatus::AtHub, ShipmentStatus::OutForDelivery, ShipmentStatus::FailedAttempt] as $status) {
            $this->move($plain, $status);
        }
        $this->returnRest($plain);

        $delivered = $this->shipment();
        foreach ([ShipmentStatus::PickedUp, ShipmentStatus::AtHub, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered] as $status) {
            $this->move($delivered, $status);
        }

        $rows = Tenancy::runFor($this->company, fn () => Shipment::query()
            ->selectRaw('shipments.id, '.Shipment::sqlRevenue().' as revenue,
                case when '.Shipment::sqlDelivered().' then 1 else 0 end as delivered,
                case when '.Shipment::sqlPlainReturn().' then 1 else 0 end as returned')
            ->toBase()->get()->keyBy('id'));

        $this->assertSame([5000, 1, 0], [(int) $rows[$partial->id]->revenue, (int) $rows[$partial->id]->delivered, (int) $rows[$partial->id]->returned]);
        $this->assertSame([2500, 0, 1], [(int) $rows[$plain->id]->revenue, (int) $rows[$plain->id]->delivered, (int) $rows[$plain->id]->returned]);
        $this->assertSame([5000, 1, 0], [(int) $rows[$delivered->id]->revenue, (int) $rows[$delivered->id]->delivered, (int) $rows[$delivered->id]->returned]);

        $host = 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
        foreach (['/reports/profit', '/reports/couriers', '/reports/merchants', '/branch-accounts', '/branch-accounts/debts', '/reports/merchant-profit', '/reports/courier-overcharge', '/reports/distribution', '/reports/pickup-performance', '/merchant-requests/payments'] as $page) {
            $this->actingAs($this->owner)->get($host.$page)->assertOk();
        }
    }

    /** ما سُلِّم بعضه قبل القرار: تُملأ ساعة تسليمه، وتُصفَّر أجرة رجوع باقيه ما لم يرجع */
    public function test_the_migration_fills_in_earlier_partial_deliveries(): void
    {
        $waiting = $this->partial();
        $returned = $this->returnRest($this->partial());
        $at = Tenancy::runFor($this->company, fn () => $waiting->events()->where('to_status', 'partially_delivered')->value('created_at'));

        // كما كانت قبل القرار: بلا ساعة تسليم، وبأجرة رجوع
        DB::table('shipments')->whereIn('id', [$waiting->id, $returned->id])->update(['delivered_at' => null, 'return_fee' => 2500]);

        (require database_path('migrations/2026_01_02_003400_backfill_partial_deliveries.php'))->up();

        [$waiting, $returned] = Tenancy::runFor($this->company, fn () => [
            Shipment::findOrFail($waiting->id), Shipment::findOrFail($returned->id)]);
        $this->assertSame((string) $at, (string) $waiting->getRawOriginal('delivered_at'));
        $this->assertSame(0, (int) $waiting->return_fee);
        $this->assertNotNull($returned->delivered_at);
        $this->assertSame(2500, (int) $returned->return_fee, 'ما رجع قبل القرار يبقى كما قُيِّد');
    }
}
