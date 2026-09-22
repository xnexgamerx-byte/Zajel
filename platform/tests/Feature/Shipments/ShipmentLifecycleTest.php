<?php

namespace Tests\Feature\Shipments;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Models\Company;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ShipmentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
        $this->company = $this->makeCompany();
    }

    private function newShipment(array $overrides = []): Shipment
    {
        $merchant = $this->makeMerchant($this->company);
        $actor = $this->makeUser($this->company);

        return Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle(array_merge([
            'merchant_id'     => $merchant->id,
            'recipient_name'  => 'علي حسين',
            'recipient_phone' => '07801234567',
            'governorate_id'  => $this->baghdad()->id,
            'address'         => 'بغداد - الكرادة',
            'landmark'        => 'مقابل جامع الشيخ معروف',
            'weight_grams'    => 1500,
            'cod_amount'      => 50_000,
        ], $overrides), $actor));
    }

    public function test_creating_a_shipment_assigns_a_number_and_logs_the_first_event(): void
    {
        $shipment = $this->newShipment();

        $this->assertSame('000001', $shipment->number);
        $this->assertSame(ShipmentStatus::Created, $shipment->status);

        Tenancy::runFor($this->company, function () use ($shipment) {
            $events = ShipmentEvent::where('shipment_id', $shipment->id)->get();
            $this->assertCount(1, $events);
            $this->assertNull($events->first()->from_status);
            $this->assertSame('created', $events->first()->to_status);
        });
    }

    public function test_shipment_numbers_are_sequential_per_company(): void
    {
        $this->newShipment();
        $second = $this->newShipment(['recipient_name' => 'زينب']);

        $this->assertSame('000002', $second->number);

        // شركة أخرى تبدأ ترقيمها من جديد
        $other = $this->makeCompany('other', 'شركة ثانية');
        $merchant = $this->makeMerchant($other);
        $actor = $this->makeUser($other);

        $otherShipment = Tenancy::runFor($other, fn () => app(CreateShipment::class)->handle([
            'merchant_id'     => $merchant->id,
            'recipient_name'  => 'كرار',
            'recipient_phone' => '07701112233',
            'governorate_id'  => $this->baghdad()->id,
            'address'         => 'بغداد',
            'landmark'        => 'قرب السوق',
        ], $actor));

        $this->assertSame('000001', $otherShipment->number);
    }

    public function test_pricing_applies_the_price_list_and_computes_merchant_due(): void
    {
        $shipment = $this->newShipment(['cod_amount' => 50_000, 'weight_grams' => 1500]);

        $this->assertSame(5000, $shipment->delivery_fee);
        $this->assertSame(5000, $shipment->total_fees);
        $this->assertSame(45_000, $shipment->merchant_due);   // 50,000 - 5,000
    }

    public function test_when_the_customer_pays_the_fee_the_merchant_gets_the_full_cod(): void
    {
        $shipment = $this->newShipment([
            'cod_amount'   => 50_000,
            'fees_paid_by' => 'customer',
        ]);

        $this->assertSame(50_000, $shipment->merchant_due);
    }

    public function test_extra_weight_adds_a_fee(): void
    {
        $shipment = $this->newShipment(['weight_grams' => 7500]);   // 2.5 كغم فوق الحد

        $this->assertSame(3000, $shipment->extra_fee);             // 3 × 1000
        $this->assertSame(8000, $shipment->total_fees);
    }

    public function test_a_legal_status_path_is_recorded_event_by_event(): void
    {
        $shipment = $this->newShipment();
        $actor = $this->makeUser($this->company);
        $change = app(ChangeShipmentStatus::class);

        Tenancy::runFor($this->company, function () use ($shipment, $actor, $change) {
            foreach ([
                ShipmentStatus::PickedUp,
                ShipmentStatus::AtHub,
                ShipmentStatus::OutForDelivery,
                ShipmentStatus::Delivered,
            ] as $status) {
                $change->handle($shipment, $status, $actor);
                $shipment->refresh();
            }

            $this->assertSame(ShipmentStatus::Delivered, $shipment->status);
            $this->assertNotNull($shipment->delivered_at);
            $this->assertNotNull($shipment->picked_up_at);
            $this->assertSame(50_000, $shipment->collected_amount);
            $this->assertCount(5, ShipmentEvent::where('shipment_id', $shipment->id)->get());
        });
    }

    public function test_an_illegal_status_jump_is_rejected(): void
    {
        $shipment = $this->newShipment();
        $actor = $this->makeUser($this->company);

        $this->expectException(ValidationException::class);

        Tenancy::runFor($this->company, fn () => app(ChangeShipmentStatus::class)
            ->handle($shipment, ShipmentStatus::Delivered, $actor));
    }

    public function test_a_delivered_shipment_is_terminal(): void
    {
        $shipment = $this->newShipment();
        $actor = $this->makeUser($this->company);
        $change = app(ChangeShipmentStatus::class);

        Tenancy::runFor($this->company, function () use ($shipment, $actor, $change) {
            foreach ([ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered] as $s) {
                $change->handle($shipment, $s, $actor);
                $shipment->refresh();
            }

            $this->assertTrue($shipment->isTerminal());
            $this->assertSame([], $shipment->status->allowedNext());
        });
    }

    public function test_a_failed_attempt_increments_the_counter_and_keeps_the_reason(): void
    {
        $shipment = $this->newShipment();
        $actor = $this->makeUser($this->company);
        $change = app(ChangeShipmentStatus::class);

        Tenancy::runFor($this->company, function () use ($shipment, $actor, $change) {
            $reason = \App\Models\FailureReason::where('code', 'no_answer')->firstOrFail();

            $change->handle($shipment, ShipmentStatus::PickedUp, $actor);
            $change->handle($shipment->refresh(), ShipmentStatus::OutForDelivery, $actor);
            $change->handle($shipment->refresh(), ShipmentStatus::FailedAttempt, $actor, [
                'failure_reason_id' => $reason->id,
                'note'              => 'اتصلت ثلاث مرات',
            ]);

            $shipment->refresh();

            $this->assertSame(1, $shipment->attempts_count);
            $this->assertSame($reason->id, $shipment->last_failure_reason_id);
            $this->assertSame('customer', $shipment->lastFailureReason->category);
        });
    }

    public function test_search_finds_a_shipment_by_receipt_number_and_by_phone(): void
    {
        $shipment = $this->newShipment();

        Tenancy::runFor($this->company, function () use ($shipment) {
            $this->assertSame($shipment->id, Shipment::search($shipment->number)->first()?->id);
            $this->assertSame($shipment->id, Shipment::search('07801234567')->first()?->id);
            $this->assertNull(Shipment::search('999999')->first());
        });
    }
}
