<?php

namespace Tests\Feature\Money;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\ConfirmAmount;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ledger;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مطابقة الدفتر بالشحنات.
 *
 * reconcile() كان يقول «مطابق» لتاجرٍ رصيده سالب ٧٣٣ مليوناً: الرصيد يساوي
 * القيود، والقيود نفسها لا تساوي الشحنات. هنا الثابت الثاني.
 */
class LedgerReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $staff;

    private Courier $courier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->staff = $this->makeUser($this->company);
        $this->courier = Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => 'C1', 'name' => 'أحمد', 'phone' => '07720000001',
            'type' => 'delivery', 'status' => 'active', 'commission_per_delivery' => 1500,
        ]));
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function delivered(int $cod = 60_000): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($cod) {
            $shipment = app(CreateShipment::class)->handle([
                'merchant_id' => $this->merchant->id, 'recipient_name' => 'علي',
                'recipient_phone' => '07801234567', 'governorate_id' => $this->baghdad()->id,
                'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => $cod,
            ], $this->staff);

            $change = app(ChangeShipmentStatus::class);
            $change->handle($shipment->refresh(), ShipmentStatus::PickedUp, $this->staff);
            $change->handle($shipment->refresh(), ShipmentStatus::OutForDelivery, $this->staff, ['courier_id' => $this->courier->id]);
            $change->handle($shipment->refresh(), ShipmentStatus::Delivered, $this->staff);

            return $shipment->refresh();
        });
    }

    private function off(): \Illuminate\Support\Collection
    {
        return Tenancy::runFor($this->company, fn () => app(Ledger::class)->shipmentsOffLedger());
    }

    public function test_a_ledger_kept_through_the_actions_reconciles(): void
    {
        $this->delivered();
        $corrected = $this->delivered(80_000);
        Tenancy::runFor($this->company, fn () => app(ConfirmAmount::class)->handle($corrected, 75_000, $this->staff));

        $this->assertCount(0, $this->off());
        $this->artisan('zajel:reconcile')->assertExitCode(0);
    }

    /** ما رأيتُه في البيانات الموسَّعة: «مسلَّمة» كُتبت مباشرةً ولم يُقيَّد لها شيء. */
    public function test_a_shipment_marked_delivered_outside_the_ledger_is_caught(): void
    {
        $this->delivered();
        $shipment = $this->delivered();

        Tenancy::runFor($this->company, fn () => Transaction::where('shipment_id', $shipment->id)
            ->where('account_type', 'merchant')->delete());

        $off = $this->off();
        $this->assertSame([$shipment->id], $off->pluck('id')->all());
        $this->assertSame(0, (int) $off->first()->posted);
        $this->assertSame((int) $shipment->merchant_due, (int) $off->first()->expected);

        $this->artisan('zajel:reconcile')->assertExitCode(1);
    }

    public function test_a_shipment_posted_twice_is_caught(): void
    {
        $shipment = $this->delivered();

        Tenancy::runFor($this->company, function () use ($shipment) {
            $due = Transaction::where('shipment_id', $shipment->id)->where('category', 'shipment_due')->firstOrFail();
            Transaction::create(collect($due->getAttributes())->except(['id', 'created_at'])->all());
        });

        $row = $this->off()->first();
        $this->assertSame($shipment->id, $row->id);
        $this->assertSame(2 * (int) $shipment->merchant_due, (int) $row->posted);
    }

    public function test_a_balance_edited_outside_the_ledger_is_caught(): void
    {
        $this->delivered();

        Tenancy::runFor($this->company, fn () => Merchant::whereKey($this->merchant->id)->increment('balance', 1_000));

        $off = Tenancy::runFor($this->company, fn () => app(Ledger::class)->balancesOff());
        $this->assertSame([$this->merchant->id], $off['merchants']->pluck('id')->all());
        $this->assertSame(1_000, $off['merchants']->first()->stored - $off['merchants']->first()->ledger);
    }

    public function test_the_screen_names_the_merchant_and_the_shipment(): void
    {
        $shipment = $this->delivered();
        Tenancy::runFor($this->company, fn () => Transaction::where('shipment_id', $shipment->id)->where('account_type', 'merchant')->delete());

        $this->actingAs($this->staff)
            ->get($this->host().'/money/reconcile?merchant_id='.$this->merchant->id)
            ->assertOk()
            ->assertSee($shipment->number)
            ->assertSee($this->merchant->business_name);
    }

    /** ما كُتب في الدفتر يبقى كما كُتب: التصحيح حركةٌ معاكسة لا تعديل. */
    public function test_ledger_rows_cannot_be_rewritten_or_deleted_through_the_model(): void
    {
        $shipment = $this->delivered();

        Tenancy::runFor($this->company, function () use ($shipment) {
            $row = Transaction::where('shipment_id', $shipment->id)->firstOrFail();

            foreach ([fn () => $row->update(['amount' => 1]), fn () => $row->delete()] as $attempt) {
                try {
                    $attempt();
                    $this->fail('عُدِّل صفٌّ في الدفتر أو حُذف.');
                } catch (\LogicException) {
                    // المتوقَّع
                }
            }

            $this->assertSame((int) $row->getOriginal('amount'), (int) $row->fresh()->amount);

            $event = \App\Models\ShipmentEvent::where('shipment_id', $shipment->id)->firstOrFail();
            $this->expectException(\LogicException::class);
            $event->update(['note' => 'تاريخٌ آخر']);
        });
    }

    /** وطرفُ المناقلة يُكتب مع الحركة، فلا تحتاج المناقلة تعديلاً بعدها. */
    public function test_a_transfer_records_both_counterparts_without_rewriting(): void
    {
        Tenancy::runFor($this->company, function () {
            $make = fn ($code) => \App\Models\CashBox::create(['code' => $code, 'name' => $code, 'type' => 'main', 'balance' => 0, 'is_active' => true]);
            [$a, $b] = [$make('A'), $make('B')];
            $cash = app(\App\Services\CashBook::class);
            $cash->in($a, 'opening', 100_000, null, $this->staff);

            [$sent, $received] = $cash->transfer($a, $b, 40_000, null, $this->staff);

            $this->assertSame($b->id, (int) $sent->fresh()->counterpart_box_id);
            $this->assertSame($a->id, (int) $received->fresh()->counterpart_box_id);
        });
    }

    public function test_one_companys_ledger_is_not_reconciled_against_anothers(): void
    {
        $shipment = $this->delivered();
        Tenancy::runFor($this->company, fn () => Transaction::where('shipment_id', $shipment->id)->where('account_type', 'merchant')->delete());

        $other = $this->makeCompany('barq', 'البرق');

        $this->assertCount(0, Tenancy::runFor($other, fn () => app(Ledger::class)->shipmentsOffLedger()));
    }
}
