<?php

namespace Tests\Feature\Shipments;

use App\Actions\Returns\ReceiveReturns;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Actions\Shipments\ProcessFailedAttempt;
use App\Actions\Shipments\SendOutForDelivery;
use App\Enums\ShipmentStatus;
use App\Models\Company;
use App\Models\Courier;
use App\Models\FailureReason;
use App\Models\Governorate;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * مسارات الشحنة كما تمرّ فعلاً في الخدمات (docs/plan/65): كل مسارٍ من الإنشاء إلى نهايته،
 * وكل انتقالٍ مرفوض يبقى مرفوضاً ولا يترك الشحنة في حالةٍ غريبة.
 */
class ShipmentFlowTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $staff;

    private Courier $courier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->staff = $this->makeUser($this->company);
        $this->courier = Tenancy::runFor($this->company, fn () => Courier::create([
            'code' => 'C1', 'name' => 'مندوب بغداد', 'phone' => '07721110001', 'type' => 'delivery',
            'status' => 'active', 'commission_per_delivery' => 5_000, 'commission_per_return' => 2_000,
        ]));
    }

    private function create(array $extra = []): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($extra) {
            $shipment = app(CreateShipment::class)->handle(array_merge([
                'merchant_id' => $this->makeMerchant($this->company, 'M'.random_int(1000, 9999))->id,
                'recipient_name' => 'زبون', 'recipient_phone' => '07801234567',
                'governorate_id' => Governorate::where('code', 'BSR')->firstOrFail()->id,
                'address' => 'البصرة، العشار', 'cod_amount' => 40_000,
            ], $extra), $this->staff);

            return $shipment->refresh();
        });
    }

    private function move(Shipment $shipment, ShipmentStatus $to, array $options = []): Shipment
    {
        return Tenancy::runFor($this->company, fn () => app(ChangeShipmentStatus::class)
            ->handle($shipment->refresh(), $to, $this->staff, $options));
    }

    private function statusOf(Shipment $shipment): ShipmentStatus
    {
        return Tenancy::runFor($this->company, fn () => $shipment->refresh()->status);
    }

    /** الانتقالات كما سُجّلت: ما يثبت أن الشحنة مرّت بكل حالةٍ وفي ترتيبها */
    private function trail(Shipment $shipment): array
    {
        return Tenancy::runFor($this->company, fn () => ShipmentEvent::where('shipment_id', $shipment->id)
            ->where('event_type', 'status_change')->orderBy('id')->pluck('to_status')->all());
    }

    private function toAtHub(): Shipment
    {
        $shipment = $this->create();
        $this->move($shipment, ShipmentStatus::PickedUp);
        $this->move($shipment, ShipmentStatus::AtHub);

        return $shipment;
    }

    private function outWithCourier(Shipment $shipment): Shipment
    {
        return Tenancy::runFor($this->company, function () use ($shipment) {
            app(SendOutForDelivery::class)->handle(collect([$shipment->refresh()]), $this->courier, $this->staff);

            return $shipment->refresh();
        });
    }

    private function reason(): FailureReason
    {
        return FailureReason::availableFor($this->company->id)->where('requires_note', false)->firstOrFail();
    }

    public function test_the_normal_path_from_creation_to_delivery_moves_the_money_once(): void
    {
        $shipment = $this->outWithCourier($this->toAtHub());
        $this->assertSame(ShipmentStatus::OutForDelivery, $this->statusOf($shipment));

        $this->move($shipment, ShipmentStatus::Delivered);

        $this->assertSame(['created', 'picked_up', 'at_hub', 'out_for_delivery', 'delivered'], $this->trail($shipment));
        Tenancy::runFor($this->company, function () use ($shipment) {
            $this->assertSame(40_000, (int) $shipment->refresh()->collected_amount);
            $this->assertSame(40_000, (int) $this->courier->refresh()->cash_in_hand);
        });

        $this->assertRefused($shipment, ShipmentStatus::OutForDelivery);
        $this->assertRefused($shipment, ShipmentStatus::Returning);
    }

    public function test_failed_then_postponed_then_redelivered_then_delivered(): void
    {
        $shipment = $this->outWithCourier($this->toAtHub());
        $this->move($shipment, ShipmentStatus::FailedAttempt, ['failure_reason_id' => $this->reason()->id, 'note' => 'لا يرد']);

        Tenancy::runFor($this->company, fn () => app(ProcessFailedAttempt::class)
            ->handle($shipment->refresh(), 'postpone', $this->staff, 'staff', now()->addDay()->toDateString(), 'يطلب الغد'));
        $this->assertSame(ShipmentStatus::Postponed, $this->statusOf($shipment));

        // المؤجَّل يخرج مع مندوبه في موعده (من شاشة الإسناد)، ومعالجة «إعادة التوصيل» للفاشل وحده
        $this->move($shipment, ShipmentStatus::OutForDelivery, ['courier_id' => $this->courier->id]);
        $this->assertSame(ShipmentStatus::OutForDelivery, $this->statusOf($shipment));

        $this->move($shipment, ShipmentStatus::Delivered);
        $this->assertSame(
            ['created', 'picked_up', 'at_hub', 'out_for_delivery', 'failed_attempt', 'postponed', 'out_for_delivery', 'delivered'],
            $this->trail($shipment),
        );
    }

    public function test_failed_then_returned_to_the_merchant_after_we_receive_it(): void
    {
        $shipment = $this->outWithCourier($this->toAtHub());
        $this->move($shipment, ShipmentStatus::FailedAttempt, ['failure_reason_id' => $this->reason()->id, 'note' => 'رفض']);

        Tenancy::runFor($this->company, fn () => app(ProcessFailedAttempt::class)->handle($shipment->refresh(), 'return', $this->staff));
        $this->assertSame(ShipmentStatus::Returning, $this->statusOf($shipment));

        // الراجع لا يُسلَّم للتاجر قبل أن يصل إلينا من المندوب
        $this->assertRefused($shipment, ShipmentStatus::Returned);

        Tenancy::runFor($this->company, fn () => app(ReceiveReturns::class)->handle([$shipment->id], $this->staff));
        $this->move($shipment, ShipmentStatus::Returned);

        $this->assertSame(ShipmentStatus::Returned, $this->statusOf($shipment));
        $this->assertSame(['created', 'picked_up', 'at_hub', 'out_for_delivery', 'failed_attempt', 'returning', 'returned'], $this->trail($shipment));
    }

    public function test_partial_delivery_returns_the_rest_to_the_merchant(): void
    {
        $shipment = $this->outWithCourier($this->toAtHub());
        $this->move($shipment, ShipmentStatus::PartiallyDelivered, ['collected_amount' => 25_000]);

        $this->assertSame(25_000, (int) Tenancy::runFor($this->company, fn () => $shipment->refresh()->collected_amount));
        $this->assertRefused($shipment, ShipmentStatus::OutForDelivery);

        $this->move($shipment, ShipmentStatus::Returning);
        Tenancy::runFor($this->company, fn () => app(ReceiveReturns::class)->handle([$shipment->id], $this->staff));
        $this->move($shipment, ShipmentStatus::Returned);

        $this->assertSame(['created', 'picked_up', 'at_hub', 'out_for_delivery', 'partially_delivered', 'returning', 'returned'], $this->trail($shipment));
    }

    public function test_transfer_between_branches_leaves_and_returns_to_the_shelf(): void
    {
        $shipment = $this->toAtHub();

        $this->move($shipment, ShipmentStatus::InTransit, ['note' => 'مع الكشف']);
        $this->assertSame(ShipmentStatus::InTransit, $this->statusOf($shipment));

        $this->move($shipment, ShipmentStatus::AtHub);
        $this->assertSame(ShipmentStatus::AtHub, $this->statusOf($shipment));
    }

    public function test_cancelled_before_it_leaves_is_final(): void
    {
        $shipment = $this->create();
        $this->move($shipment, ShipmentStatus::Cancelled);

        $this->assertSame(ShipmentStatus::Cancelled, $this->statusOf($shipment));
        foreach (ShipmentStatus::cases() as $to) {
            if ($to !== ShipmentStatus::Cancelled) {
                $this->assertRefused($shipment, $to);
            }
        }
    }

    public function test_lost_on_the_road_is_final_and_moves_no_money(): void
    {
        $shipment = $this->outWithCourier($this->toAtHub());
        $this->move($shipment, ShipmentStatus::Lost);

        $this->assertSame(ShipmentStatus::Lost, $this->statusOf($shipment));
        Tenancy::runFor($this->company, fn () => $this->assertSame(0, (int) $this->courier->refresh()->cash_in_hand));
        $this->assertRefused($shipment, ShipmentStatus::Delivered);
    }

    public function test_every_transition_the_map_does_not_allow_is_refused_and_leaves_the_status_alone(): void
    {
        foreach (ShipmentStatus::cases() as $from) {
            $shipment = $this->create();
            // نضع الشحنة في الحالة مباشرةً (الاختبار يفحص الانتقال لا الطريق إليه)
            Tenancy::runFor($this->company, fn () => $shipment->forceFill(['status' => $from->value])->save());

            foreach (ShipmentStatus::cases() as $to) {
                if ($to === $from || $from->canMoveTo($to)) {
                    continue;
                }

                $this->assertRefused($shipment, $to, "من {$from->value} إلى {$to->value}");
            }
        }
    }

    public function test_the_map_has_no_way_out_of_a_final_status(): void
    {
        foreach (ShipmentStatus::terminal() as $final) {
            $this->assertSame([], $final->allowedNext(), $final->value);
        }
    }

    private function assertRefused(Shipment $shipment, ShipmentStatus $to, ?string $label = null): void
    {
        $before = $this->statusOf($shipment);

        try {
            $this->move($shipment, $to);
            $this->fail(($label ?? "{$before->value} → {$to->value}").' كان يجب أن يُرفض');
        } catch (ValidationException) {
            $this->assertSame($before, $this->statusOf($shipment), 'الحالة لم تتغيّر بعد الرفض');
        }
    }
}
