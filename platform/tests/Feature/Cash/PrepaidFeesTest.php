<?php

namespace Tests\Feature\Cash;

use App\Actions\Cash\ReceivePrepaidFees;
use App\Actions\Settlements\BuildMerchantSettlement;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Actions\Shipments\UpdateShipment;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\CashBox;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\PrepaidReceipt;
use App\Models\Shipment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashBook;
use App\Services\Ledger;
use App\Services\Money\FinancialPosition;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * أجرة التوصيل مدفوعةً مقدّماً (docs/plan/22 §٣): تاجرٌ «يُحاسَب مقدّماً» يدفع
 * أجور شحناته حين يُرسلها — تُقبض في صندوقٍ بإيصال، وتعود إلى مستحقّه عند
 * التسليم أو الرجوع، فتدفعها تسويته. والدفتر والصندوق يبقيان مطابقَين.
 */
class PrepaidFeesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $owner;

    private Courier $courier;

    private CashBox $box;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);

        [$this->courier, $this->box] = Tenancy::runFor($this->company, function () {
            $this->merchant->update(['prepaid_billing' => true]);

            return [
                Courier::create(['code' => 'C1', 'name' => 'أحمد', 'phone' => '07720000001', 'type' => 'delivery', 'status' => 'active']),
                CashBox::create(['code' => 'MAIN', 'name' => 'القاصة الرئيسية', 'type' => 'main', 'balance' => 0, 'is_active' => true]),
            ];
        });
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function shipment(array $data = [], array $path = [ShipmentStatus::PickedUp]): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($data, $path) {
            $shipment = app(CreateShipment::class)->handle($data + [
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي', 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => 50_000,
            ], $this->owner);

            $this->move($shipment, ...$path);

            return $shipment->refresh();
        });
    }

    private function move(Shipment $shipment, ShipmentStatus ...$path): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($shipment, $path) {
            foreach ($path as $status) {
                app(ChangeShipmentStatus::class)->handle($shipment->refresh(), $status, $this->owner,
                    ['courier_id' => $status === ShipmentStatus::OutForDelivery ? $this->courier->id : null]);
            }

            return $shipment->refresh();
        });
    }

    private function receive(Shipment ...$shipments): PrepaidReceipt
    {
        return Tenancy::runFor($this->company, fn () => app(ReceivePrepaidFees::class)->handle(
            $this->merchant, array_map(fn (Shipment $s) => $s->id, $shipments), $this->box, $this->owner));
    }

    /** الدفتر بالشحنات، وأرصدة التجّار والمناديب، والصندوق: كلّها مطابقة */
    private function assertBooksBalance(): void
    {
        Tenancy::runFor($this->company, function () {
            $ledger = app(Ledger::class);
            $this->assertCount(0, $ledger->shipmentsOffLedger(), 'شحنةٌ قيودها لا تساوي مستحقّها');
            $off = $ledger->balancesOff();
            $this->assertCount(0, $off['merchants']);
            $this->assertCount(0, $off['couriers']);
            $this->assertTrue(app(CashBook::class)->reconcile($this->box)['matches']);
        });
    }

    public function test_a_prepaid_merchants_shipments_are_marked_unless_said_otherwise(): void
    {
        $this->assertTrue($this->shipment()->fee_prepaid);
        $this->assertFalse($this->shipment(['fee_prepaid' => '0'])->fee_prepaid);
        // الأجرة على الزبون: لا يدفعها التاجر أصلاً
        $this->assertFalse($this->shipment(['fees_paid_by' => 'customer'])->fee_prepaid);

        Tenancy::runFor($this->company, fn () => $this->merchant->update(['prepaid_billing' => false]));
        $this->assertFalse($this->shipment()->fee_prepaid);
        $this->assertTrue($this->shipment(['fee_prepaid' => '1'])->fee_prepaid);
    }

    public function test_receiving_puts_the_fees_in_the_drawer_and_on_each_shipment(): void
    {
        $a = $this->shipment();
        $b = $this->shipment(['cod_amount' => 0]);
        $waiting = $this->shipment([], []);   // لم تُستلم من التاجر بعد: لا تُقبض أجرتها

        $fees = (int) $a->total_fees + (int) $b->total_fees;
        $receipt = $this->receive($a, $b, $waiting);

        $this->assertSame([$fees, 2], [(int) $receipt->amount, (int) $receipt->shipments_count]);
        $this->assertSame($fees, (int) $this->box->fresh()->balance);

        foreach ([$a, $b] as $shipment) {
            $fresh = $shipment->fresh();
            $this->assertSame((int) $shipment->total_fees, (int) $fresh->prepaid_amount);
            $this->assertSame($receipt->id, $fresh->prepaid_receipt_id);
            // المستحقّ المقدَّر: المبلغ كاملاً — لا تُخصم أجرةٌ دُفعت
            $this->assertSame((int) $shipment->cod_amount, (int) $fresh->merchant_due);
        }
        $this->assertNull($waiting->fresh()->prepaid_receipt_id);

        // ولا يُقبض مرّتين
        $this->expectException(ValidationException::class);
        $this->receive($a);
    }

    public function test_after_delivery_the_merchant_is_owed_the_whole_amount_and_the_books_balance(): void
    {
        $shipment = $this->shipment();
        $fees = (int) $shipment->total_fees;
        $this->receive($shipment);

        $delivered = $this->move($shipment, ShipmentStatus::AtHub, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered);

        $this->assertSame(50_000, (int) $delivered->merchant_due);
        $this->assertSame(50_000, (int) $this->merchant->fresh()->balance);

        Tenancy::runFor($this->company, function () use ($shipment, $fees) {
            $lines = Transaction::where('shipment_id', $shipment->id)->where('account_type', 'merchant')
                ->orderBy('id')->get(['category', 'amount'])->map(fn ($t) => [$t->category, (int) $t->amount])->all();
            $this->assertSame([['shipment_due', 50_000 - $fees], ['prepaid_fee', $fees]], $lines);

            // وتسويته تدفع المبلغ كاملاً
            $this->settleCourierCash($this->company);   // كشف التاجر بعد محاسبة المندوب (docs/plan/49)
            $this->assertSame(50_000, (int) app(BuildMerchantSettlement::class)->handle($this->merchant, $this->owner)->net_amount);
        });

        $this->assertBooksBalance();
    }

    public function test_a_returned_prepaid_shipment_gives_back_what_was_paid_less_the_return_fee(): void
    {
        $shipment = $this->shipment();
        $this->receive($shipment);

        $returned = $this->move($shipment, ShipmentStatus::AtHub, ShipmentStatus::Returning, ShipmentStatus::Returned);

        $this->assertSame((int) $shipment->total_fees - (int) $shipment->return_fee, (int) $returned->merchant_due);
        $this->assertSame((int) $returned->merchant_due, (int) $this->merchant->fresh()->balance);
        $this->assertBooksBalance();
    }

    /**
     * باقي الواصل الجزئي يرجع لتاجره: ما بيع منه قُيِّد له عند التسليم ويبقى، ولا أجرة
     * راجعٍ عليه (الوثيقة ٢٤) — وما دفعه مقدّماً لا يُقيَّد له مرّتين. وتسويته تدفع ما في دفتره.
     */
    public function test_the_rest_of_a_partial_delivery_returns_without_losing_what_was_sold(): void
    {
        foreach ([false, true] as $prepaid) {
            $shipment = $this->shipment(['fee_prepaid' => $prepaid ? '1' : '0']);
            if ($prepaid) {
                $this->receive($shipment);
            }

            $this->move($shipment, ShipmentStatus::AtHub, ShipmentStatus::OutForDelivery);
            $partial = Tenancy::runFor($this->company, fn () => app(ChangeShipmentStatus::class)->handle(
                $shipment->refresh(), ShipmentStatus::PartiallyDelivered, $this->owner, ['collected_amount' => 30_000]));
            $sold = (int) $partial->merchant_due;

            $this->move($shipment, ShipmentStatus::Returning);
            Tenancy::runFor($this->company, fn () => app(\App\Actions\Returns\ReceiveReturns::class)->handle([$shipment->id], $this->owner));
            $returned = $this->move($shipment, ShipmentStatus::Returned);

            $this->assertSame(30_000 - (int) $shipment->total_fees + ($prepaid ? (int) $shipment->total_fees : 0), $sold);
            $this->assertSame($sold, (int) $returned->merchant_due, $prepaid ? 'مقدّماً' : 'عاديّة');
            $this->assertBooksBalance();
        }
    }

    public function test_a_shipment_whose_fees_were_received_cannot_be_cancelled(): void
    {
        $shipment = $this->shipment();
        $this->receive($shipment);

        $this->expectException(ValidationException::class);
        $this->move($shipment, ShipmentStatus::Cancelled);
    }

    public function test_delivered_before_paying_falls_back_to_the_usual_deduction(): void
    {
        $shipment = $this->shipment();
        $delivered = $this->move($shipment, ShipmentStatus::AtHub, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered);

        $this->assertSame(50_000 - (int) $shipment->total_fees, (int) $delivered->merchant_due);
        $this->assertSame(0, Tenancy::runFor($this->company,
            fn () => ReceivePrepaidFees::pending(Shipment::query())->count()));
        $this->assertBooksBalance();
    }

    public function test_fees_raised_after_paying_deduct_only_the_difference(): void
    {
        $shipment = $this->shipment();
        $this->receive($shipment);

        Tenancy::runFor($this->company, fn () => app(UpdateShipment::class)->handle($shipment->refresh(), [
            ...$shipment->only(['recipient_name', 'recipient_phone', 'governorate_id', 'city_id', 'address',
                'landmark', 'pieces_count', 'weight_grams', 'cod_amount', 'fees_paid_by', 'discount']),
            'extra_fee' => 1500,
        ], $this->owner));

        $this->assertSame(48_500, (int) $shipment->fresh()->merchant_due);
        $this->assertTrue($shipment->fresh()->fee_prepaid);

        $delivered = $this->move($shipment, ShipmentStatus::AtHub, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered);
        $this->assertSame(48_500, (int) $delivered->merchant_due);
        $this->assertBooksBalance();
    }

    public function test_the_financial_position_owes_what_was_received_until_the_shipment_completes(): void
    {
        $shipment = $this->shipment();
        $this->receive($shipment);

        $held = fn () => Tenancy::runFor($this->company, fn () => app(FinancialPosition::class)->now($this->owner)['figures']['prepaid_held']);
        $this->assertSame((int) $shipment->total_fees, $held());

        $this->move($shipment, ShipmentStatus::AtHub, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered);
        $this->assertSame(0, $held());
    }

    public function test_the_screen_lists_what_waits_and_receives_it(): void
    {
        $shipment = $this->shipment();

        $this->actingAs($this->owner)->get($this->host().'/prepaid-fees')->assertOk()
            ->assertSee('متجر M0001');
        $this->actingAs($this->owner)->get($this->host().'/prepaid-fees?merchant_id='.$this->merchant->id)->assertOk()
            ->assertSee($shipment->number);

        $this->actingAs($this->owner)->post($this->host().'/prepaid-fees', [
            'merchant_id' => $this->merchant->id, 'shipment_ids' => [$shipment->id], 'cash_box_id' => $this->box->id,
        ])->assertRedirect()->assertSessionHas('success', fn (string $m) => str_contains($m, 'إيصال PR'));

        $this->assertNotNull($shipment->fresh()->prepaid_receipt_id);
        $this->actingAs($this->owner)->get($this->host().'/shipments/'.$shipment->id)->assertOk()
            ->assertSee('دفعها التاجر مقدّماً');

        // ولمن يملك الصندوق وحده
        $operations = $this->makeUser($this->company, UserRole::Operations);
        $this->actingAs($operations)->get($this->host().'/prepaid-fees')->assertForbidden();
    }

    public function test_quick_entry_marks_a_row_prepaid(): void
    {
        Tenancy::runFor($this->company, fn () => $this->merchant->update(['prepaid_billing' => false]));

        $this->actingAs($this->owner)->post($this->host().'/shipments/quick', [
            'mode' => 'merchant', 'merchant_id' => $this->merchant->id, 'fees_paid_by' => 'merchant',
            'rows' => [
                ['governorate_id' => $this->baghdad()->id, 'city_id' => $this->area(), 'recipient_phone' => '07801234567', 'amount' => '25', 'prepaid' => '1'],
                ['governorate_id' => $this->baghdad()->id, 'city_id' => $this->area(), 'recipient_phone' => '07801234568', 'amount' => '30'],
            ],
        ])->assertSessionHasNoErrors();

        $flags = Tenancy::runFor($this->company, fn () => Shipment::orderBy('id')->pluck('fee_prepaid')->all());
        $this->assertSame([true, false], $flags);
    }
}
