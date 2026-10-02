<?php

namespace App\Actions\Shipments;

use App\Enums\ShipmentStatus;
use App\Models\Courier;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * إسناد مجموعة شحنات إلى مندوب وإخراجها للتوصيل دفعة واحدة.
 *
 * هذه العملية اليومية الأكثر تكراراً في شركة توصيل: صباحاً يوزَّع ما في
 * المخزن على المندوبين. شحنة شحنة يعني ساعة عمل كل يوم.
 *
 * وما لم يُستلم بعد («جديد» أو «بانتظار المندوب») يُسجَّل استلامه ثم يخرج —
 * من يُسندها بيده الطرد، كما في الإدخال السريع ومسح «مندوب توصيل للكل» في
 * المعتاد. وما عدا ذلك يُتخطّى ويُقال لماذا، شحنةً شحنة.
 */
class SendOutForDelivery
{
    public function __construct(protected ChangeShipmentStatus $change) {}

    /**
     * @param  Collection<int, Shipment>  $shipments  ما يراه المستخدم وحده — يُصفّى قبل الاستدعاء
     * @return array{moved: list<string>, received: list<string>, skipped: array<string, string>}
     */
    public function handle(Collection $shipments, Courier $courier, User $actor, string $note = 'إسناد جماعي'): array
    {
        $moved = [];
        $received = [];
        $skipped = [];

        foreach ($shipments as $shipment) {
            if ($reason = $this->cannotGoOut($shipment, $courier)) {
                $skipped[$shipment->number] = $reason;

                continue;
            }

            try {
                // الخطوتان معاً أو لا شيء: لا تبقى «مستلَمة» بلا مندوب إن سقطت الثانية
                $wasReceived = DB::transaction(function () use ($shipment, $courier, $actor, $note) {
                    $notYet = in_array($shipment->status, [ShipmentStatus::Created, ShipmentStatus::PendingPickup], true);

                    if ($notYet) {
                        $this->change->handle($shipment, ShipmentStatus::PickedUp, $actor, [
                            'note' => 'استُلمت عند الإسناد إلى '.$courier->name,
                        ]);
                    }

                    $this->change->handle($shipment->refresh(), ShipmentStatus::OutForDelivery, $actor, [
                        'courier_id' => $courier->id,
                        'note'       => $note,
                    ]);

                    return $notYet;
                });
            } catch (ValidationException $e) {
                // سبقه أحدٌ إليها بين الاختيار والحفظ: تُتخطّى بسببها ولا يسقط الباقي
                $skipped[$shipment->number] = collect($e->errors())->flatten()->first();

                continue;
            }

            $moved[] = $shipment->number;

            if ($wasReceived) {
                $received[] = $shipment->number;
            }
        }

        return ['moved' => $moved, 'received' => $received, 'skipped' => $skipped];
    }

    /** لماذا لا تخرج هذه الشحنة مع هذا المندوب — أو null إن كانت تخرج */
    protected function cannotGoOut(Shipment $shipment, Courier $courier): ?string
    {
        $status = $shipment->status;

        return match (true) {
            // والمعلَّقة للمراجعة لا تخرج حتى تُجاز
            $shipment->isHeldForReview()                  => 'تحت المراجعة',
            $status === ShipmentStatus::OutForDelivery    => (int) $shipment->delivery_courier_id === (int) $courier->id
                ? 'معه سلفاً'
                : 'مع '.($shipment->deliveryCourier?->name ?? 'مندوبٍ آخر'),
            in_array($status, [ShipmentStatus::Created, ShipmentStatus::PendingPickup], true) => null,
            ! $status->canMoveTo(ShipmentStatus::OutForDelivery) => $status->label(),
            // وباقي الواصل الجزئي راجعٌ لتاجره (الوثيقة ٢٤)
            $shipment->wasDelivered()                      => 'واصل جزئي — باقيه راجعٌ لتاجره',
            default                                        => null,
        };
    }
}
